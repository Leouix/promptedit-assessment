<?php

namespace Tests\Feature;

use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use App\Models\User;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GenerationSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private const PRICING = [
        'object' => 'pricing',
        'currency' => 'usd',
        'unit' => 'per_1m_tokens',
        'rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'tokens' => [
            'characters_per_text_token' => 4,
            'per_reference_image' => 765,
            'output' => [
                'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
            ],
            'reasoning' => [
                'low' => ['min' => 0, 'max' => 500],
                'medium' => ['min' => 200, 'max' => 2000],
                'high' => ['min' => 1000, 'max' => 8000],
            ],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            '*/pricing' => Http::response(self::PRICING),
        ]);
    }

    public function test_estimate_matches_the_published_token_tables(): void
    {
        $pricing = app(PricingService::class);
        $prompt = str_repeat('a', 400); // 100 text tokens
        $withTwoRefs = str_repeat('a', 400);

        // medium 1024x1024, no refs: text 100*5/1e6=0.0005, output 1100*40/1e6=0.044,
        // reasoning midpoint 1100*12.5/1e6=0.01375 -> 0.05825
        $this->assertSame('0.058250000000', $pricing->estimateProviderCost($prompt, '1024x1024', 'medium', 0));

        // high 1536x1024, 2 refs: text 0.0005, images 1530*10/1e6=0.0153,
        // output 6450*40/1e6=0.258, reasoning 4500*12.5/1e6=0.05625 -> 0.33005
        $this->assertSame('0.330050000000', $pricing->estimateProviderCost($withTwoRefs, '1536x1024', 'high', 2));
    }

    public function test_user_can_submit_a_generation(): void
    {
        Queue::fake();
        $user = User::factory()->create(['credits' => '10.000000000000']);

        $response = $this->actingAs($user)->post('/generations', [
            'prompt' => 'A lighthouse at dawn',
            'size' => '1024x1024',
            'quality' => 'medium',
        ]);

        $response->assertRedirect();
        $generation = $user->generations()->first();

        $this->assertNotNull($generation);
        $this->assertSame(Generation::STATUS_PENDING, $generation->status);
        // Estimate: 0.057775 provider cost * 1.25 = 0.07221875
        $this->assertSame('0.072218750000', $generation->estimate_cost);
        Queue::assertPushed(SubmitGenerationJob::class);
    }

    public function test_submission_is_blocked_when_credits_are_too_low(): void
    {
        Queue::fake();
        $user = User::factory()->create(['credits' => '0.000000000000']);

        $response = $this->actingAs($user)->post('/generations', [
            'prompt' => 'A lighthouse at dawn',
            'size' => '1024x1024',
            'quality' => 'medium',
        ]);

        $response->assertSessionHasErrors('credits');
        $this->assertSame(0, $user->generations()->count());
    }

    public function test_store_hits_pixelforge_with_the_right_payload(): void
    {
        $user = User::factory()->create(['credits' => '10.000000000000']);
        Http::fake([
            '*/pricing' => Http::response(self::PRICING),
            '*/generations' => Http::response(['id' => 'gen_new', 'status' => 'queued'], 202),
        ]);

        $response = $this->actingAs($user)->post('/generations', [
            'prompt' => 'A lighthouse at dawn',
            'size' => '1024x1024',
            'quality' => 'medium',
        ]);

        $response->assertRedirect();
        $generation = $user->generations()->first();

        (new SubmitGenerationJob($generation->id))->handle(app(\App\Services\PixelforgeClient::class));

        $generation->refresh();
        $this->assertSame(Generation::STATUS_QUEUED, $generation->status);
        $this->assertSame('gen_new', $generation->pixelforge_id);

        Http::assertSent(function (Request $request) use ($generation) {
            if (str_contains($request->url(), '/generations')) {
                $body = $request->data();

                return $request->header('Idempotency-Key')[0] === $generation->id
                    && $body['metadata']['local_id'] === $generation->id
                    && $body['prompt'] === 'A lighthouse at dawn';
            }

            return true;
        });
    }

    public function test_overloaded_release_and_retry(): void
    {
        $user = User::factory()->create();
        $generation = Generation::create([
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'user_id' => $user->id,
            'prompt' => 'p',
            'size' => '1024x1024',
            'quality' => 'medium',
            'estimate_cost' => '0.1',
            'status' => Generation::STATUS_PENDING,
        ]);

        Http::fake([
            '*/pricing' => Http::response(self::PRICING),
            '*/generations' => Http::response([
                'error' => ['type' => 'api_error', 'code' => 'overloaded', 'message' => 'Busy'],
            ], 503, ['Retry-After' => '2']),
        ]);

        $job = new SubmitGenerationJob($generation->id);
        $job->handle(app(\App\Services\PixelforgeClient::class));

        // The job backed off instead of failing or creating anything.
        $generation->refresh();
        $this->assertSame(Generation::STATUS_PENDING, $generation->status);
        $this->assertNull($generation->pixelforge_id);
    }

    public function test_content_policy_violation_fails_the_generation_without_charge(): void
    {
        $user = User::factory()->create(['credits' => '10.000000000000']);
        $generation = Generation::create([
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'user_id' => $user->id,
            'prompt' => 'bad [blocked]',
            'size' => '1024x1024',
            'quality' => 'medium',
            'estimate_cost' => '0.1',
            'status' => Generation::STATUS_PENDING,
        ]);

        Http::fake([
            '*/pricing' => Http::response(self::PRICING),
            '*/generations' => Http::response([
                'error' => [
                    'type' => 'invalid_request_error',
                    'code' => 'content_policy_violation',
                    'message' => 'The prompt was rejected.',
                ],
            ], 400),
        ]);

        (new SubmitGenerationJob($generation->id))->handle(app(\App\Services\PixelforgeClient::class));

        $generation->refresh();
        $this->assertSame(Generation::STATUS_FAILED, $generation->status);
        $this->assertSame('content_policy_violation', $generation->error['code']);
        $this->assertSame('10.000000000000', $user->refresh()->credits);
    }

    public function test_late_webhook_beats_the_submit_response(): void
    {
        // The row starts as pending; a webhook arriving before our POST
        // returns must find it via metadata.local_id and settle it.
        $user = User::factory()->create(['credits' => '10.000000000000']);
        $generation = Generation::create([
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'user_id' => $user->id,
            'prompt' => 'p',
            'size' => '1024x1024',
            'quality' => 'medium',
            'estimate_cost' => '0.1',
            'status' => Generation::STATUS_PENDING,
        ]);

        $service = app(\App\Services\GenerationService::class);
        $service->applyRemoteUpdate($generation, [
            'id' => 'gen_beat',
            'status' => 'succeeded',
            'usage' => [
                'input_text_tokens' => 4, 'input_image_tokens' => 0,
                'output_tokens' => 1100, 'reasoning_tokens' => 200,
            ],
            'rates' => [
                'input_text_tokens' => '5.00', 'input_image_tokens' => '10.00',
                'output_tokens' => '40.00', 'reasoning_tokens' => '12.50',
            ],
            'image_url' => 'https://pixelforge.promptedit.dev/images/gen_beat.png?sig=1',
        ]);

        // Now the (idempotent) submit response arrives for the same generation.
        Http::fake([
            '*/generations' => Http::response([
                'id' => 'gen_beat', 'status' => 'succeeded',
                'usage' => [
                    'input_text_tokens' => 4, 'input_image_tokens' => 0,
                    'output_tokens' => 1100, 'reasoning_tokens' => 200,
                ],
                'rates' => [
                    'input_text_tokens' => '5.00', 'input_image_tokens' => '10.00',
                    'output_tokens' => '40.00', 'reasoning_tokens' => '12.50',
                ],
            ], 202),
        ]);
        (new SubmitGenerationJob($generation->id))->handle(app(\App\Services\PixelforgeClient::class));

        $generation->refresh();
        $this->assertSame(Generation::STATUS_SUCCEEDED, $generation->status);
        $this->assertSame('0.046520000000', $generation->provider_cost); // 0.00002+0.044+0.0025
        $this->assertSame('0.058150000000', $generation->charged_cost);
        $this->assertSame('9.941850000000', $user->refresh()->credits);
    }
}
