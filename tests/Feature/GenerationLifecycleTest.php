<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use App\Services\GenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private GenerationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(GenerationService::class);
    }

    private function remote(string $status, array $overrides = []): array
    {
        return array_merge([
            'id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K',
            'status' => $status,
            'image_url' => null,
            'image_url_expires_at' => null,
            'usage' => [
                'input_text_tokens' => 12,
                'input_image_tokens' => 765,
                'output_tokens' => 6450,
                'reasoning_tokens' => 3184,
                'total_tokens' => 10411,
            ],
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
            'error' => null,
            'completed_at' => '2026-11-19T10:00:00Z',
        ], $overrides);
    }

    private function localGeneration(): Generation
    {
        $user = User::factory()->create(['credits' => '1.000000000000']);

        return Generation::create([
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'user_id' => $user->id,
            'prompt' => 'A lighthouse at dawn',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/style.png'],
            'estimate_cost' => '0.100000000000',
            'status' => Generation::STATUS_QUEUED,
        ]);
    }

    public function test_succeeded_generation_is_charged_exactly(): void
    {
        $generation = $this->localGeneration();

        // Expected provider cost:
        //   12 * 5/1e6      = 0.00006
        //   765 * 10/1e6    = 0.00765
        //   6450 * 40/1e6   = 0.258
        //   3184 * 12.5/1e6 = 0.0398
        //                   = 0.30551 exactly. Charge = x1.25 = 0.3818875.
        $this->service->applyRemoteUpdate($generation, $this->remote('succeeded', [
            'image_url' => 'https://pixelforge.promptedit.dev/images/gen_x.png?sig=1',
        ]));

        $generation->refresh();

        $this->assertSame('succeeded', $generation->status);
        $this->assertSame('0.305510000000', $generation->provider_cost);
        $this->assertSame('0.381887500000', $generation->charged_cost);
        $this->assertSame('0.618112500000', $generation->user->credits);

        // Exactly one charge transaction for this generation.
        $this->assertSame(1, $generation->user->creditTransactions()
            ->where('generation_id', $generation->id)
            ->where('type', 'charge')->count());
    }

    public function test_failed_generation_costs_nothing(): void
    {
        $generation = $this->localGeneration();

        $this->service->applyRemoteUpdate($generation, $this->remote('failed', [
            'error' => ['code' => 'generation_failed', 'message' => 'Could not produce an image.'],
        ]));

        $generation->refresh();

        $this->assertSame('failed', $generation->status);
        $this->assertNull($generation->provider_cost);
        $this->assertNull($generation->charged_cost);
        $this->assertSame('1.000000000000', $generation->user->credits);
    }

    public function test_duplicate_succeeded_webhook_never_charges_twice(): void
    {
        $generation = $this->localGeneration();

        $this->service->applyRemoteUpdate($generation, $this->remote('succeeded'));
        $this->service->applyRemoteUpdate($generation, $this->remote('succeeded'));

        $generation->refresh();

        $this->assertSame('0.618112500000', $generation->user->credits);
        $this->assertSame(1, $generation->user->creditTransactions()->count());
    }

    public function test_out_of_order_processing_event_cannot_regress_a_final_generation(): void
    {
        $generation = $this->localGeneration();

        // succeeded arrives first, then a retried processing event.
        $this->service->applyRemoteUpdate($generation, $this->remote('succeeded'));
        $this->service->applyRemoteUpdate($generation, $this->remote('processing'));

        $generation->refresh();

        $this->assertSame('succeeded', $generation->status);
        $this->assertNotNull($generation->charged_cost);
    }

    public function test_out_of_order_queued_event_cannot_regress_processing(): void
    {
        $generation = $this->localGeneration();
        $generation->update(['status' => Generation::STATUS_PROCESSING, 'pixelforge_id' => 'gen_x']);

        $this->service->applyRemoteUpdate($generation, $this->remote('queued'));

        $generation->refresh();
        $this->assertSame(Generation::STATUS_PROCESSING, $generation->status);
    }

    public function test_processing_then_succeeded_sets_final_state(): void
    {
        $generation = $this->localGeneration();

        $this->service->applyRemoteUpdate($generation, $this->remote('processing'));
        $this->assertSame(Generation::STATUS_PROCESSING, $generation->refresh()->status);

        $this->service->applyRemoteUpdate($generation, $this->remote('succeeded'));
        $this->assertSame(Generation::STATUS_SUCCEEDED, $generation->refresh()->status);
    }

    public function test_welcome_and_purchase_and_charge_flow(): void
    {
        $user = User::factory()->create();
        $user->addCredits('5.00', 'welcome');
        $user->addCredits('10.00', 'purchase');
        $user->addCredits('-0.25', 'charge');

        $this->assertSame('14.750000000000', $user->refresh()->credits);
    }
}
