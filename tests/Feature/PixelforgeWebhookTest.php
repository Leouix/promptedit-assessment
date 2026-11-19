<?php

namespace Tests\Feature;

use App\Jobs\HandleWebhookEventJob;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PixelforgeWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_secret';

    private Generation $generation;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.pixelforge.webhook_secret' => self::SECRET]);
        Queue::fake();

        $user = User::factory()->create(['credits' => '1.000000000000']);
        $this->generation = Generation::create([
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'user_id' => $user->id,
            'prompt' => 'A lighthouse at dawn',
            'size' => '1536x1024',
            'quality' => 'high',
            'estimate_cost' => '0.100000000000',
            'status' => Generation::STATUS_PENDING,
        ]);
    }

    private function sign(string $body, int $timestamp, string $secret = self::SECRET): string
    {
        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    private function postWebhook(array $payload, ?int $timestamp = null, string $secret = self::SECRET)
    {
        $body = json_encode($payload);

        return $this->call(
            'POST',
            '/webhooks/pixelforge',
            [], [], [],
            $this->transformHeadersToServerVars([
                'CONTENT_TYPE' => 'application/json',
                'Pixelforge-Signature' => $this->sign($body, $timestamp ?? time(), $secret),
            ]),
            $body,
        );
    }

    private function event(string $eventId, string $type, array $generation): array
    {
        return [
            'id' => $eventId,
            'object' => 'event',
            'type' => $type,
            'created_at' => '2026-11-19T10:00:00Z',
            'data' => ['object' => $generation],
        ];
    }

    public function test_rejects_unsigned_requests(): void
    {
        $this->post('/webhooks/pixelforge', ['id' => 'evt_x'])
            ->assertStatus(400);

        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_rejects_wrong_secret(): void
    {
        $this->postWebhook(
            $this->event('evt_1', 'generation.processing', ['id' => 'gen_x', 'status' => 'processing']),
            secret: 'whsec_wrong',
        )->assertStatus(400);

        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_rejects_replayed_old_timestamps(): void
    {
        $this->postWebhook(
            $this->event('evt_1', 'generation.processing', ['id' => 'gen_x', 'status' => 'processing']),
            timestamp: time() - 3600,
        )->assertStatus(400);

        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_valid_event_is_stored_and_job_dispatched(): void
    {
        $this->postWebhook($this->event('evt_1', 'generation.processing', [
            'id' => 'gen_x',
            'status' => 'processing',
            'metadata' => ['local_id' => $this->generation->id],
        ]))->assertStatus(204);

        $this->assertSame(1, WebhookEvent::count());
        Queue::assertPushed(HandleWebhookEventJob::class, 1);
    }

    public function test_duplicate_delivery_is_deduplicated(): void
    {
        $event = $this->event('evt_dup', 'generation.succeeded', [
            'id' => 'gen_x',
            'status' => 'succeeded',
            'metadata' => ['local_id' => $this->generation->id],
            'image_url' => 'https://pixelforge.promptedit.dev/images/gen_x.png?sig=1',
            'usage' => [
                'input_text_tokens' => 12, 'input_image_tokens' => 765,
                'output_tokens' => 6450, 'reasoning_tokens' => 3184,
            ],
            'rates' => [
                'input_text_tokens' => '5.00', 'input_image_tokens' => '10.00',
                'output_tokens' => '40.00', 'reasoning_tokens' => '12.50',
            ],
        ]);

        $this->postWebhook($event)->assertStatus(204);
        $this->postWebhook($event)->assertStatus(204);

        // One stored event, one queued job: the redelivery changed nothing.
        $this->assertSame(1, WebhookEvent::count());
        Queue::assertPushed(HandleWebhookEventJob::class, 1);
    }

    public function test_duplicate_events_with_different_ids_are_stored_but_applied_once(): void
    {
        $payload = [
            'id' => 'gen_x',
            'status' => 'succeeded',
            'metadata' => ['local_id' => $this->generation->id],
            'usage' => [
                'input_text_tokens' => 12, 'input_image_tokens' => 765,
                'output_tokens' => 6450, 'reasoning_tokens' => 3184,
            ],
            'rates' => [
                'input_text_tokens' => '5.00', 'input_image_tokens' => '10.00',
                'output_tokens' => '40.00', 'reasoning_tokens' => '12.50',
            ],
        ];

        // Pixelforge can deliver "the same" update as two distinct events;
        // each is stored, but the charge may only happen once.
        $this->postWebhook($this->event('evt_a', 'generation.succeeded', $payload));
        $this->postWebhook($this->event('evt_b', 'generation.succeeded', $payload));

        $this->assertSame(2, WebhookEvent::count());
        Queue::assertPushed(HandleWebhookEventJob::class, 2);
    }
}
