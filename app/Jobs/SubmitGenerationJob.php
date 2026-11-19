<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\PixelforgeClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Submits a pending generation to Pixelforge and copes with its misbehaviour:
 * rate limits (429), overloads (503), and rejected prompts (400). Retries use
 * the generation's uuid as the Idempotency-Key, so a retry after "overloaded"
 * never creates a second generation or double-bills anything.
 */
class SubmitGenerationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Back off harder each time, but give up eventually. */
    public const MAX_ATTEMPTS = 5;

    public int $tries = self::MAX_ATTEMPTS;

    public function __construct(public string $generationId)
    {
    }

    public function handle(PixelforgeClient $client): void
    {
        $generation = Generation::findOrFail($this->generationId);

        if ($generation->isFinal()) {
            return; // A webhook already settled this generation.
        }

        $payload = [
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
            'metadata' => [
                // Lets any webhook find our row even before this request returns.
                'local_id' => $generation->id,
            ],
        ];

        if (! empty($generation->reference_images)) {
            $payload['reference_images'] = $generation->reference_images;
        }

        $response = $client->createGeneration($payload, $generation->id);

        if ($response->successful()) {
            $remote = $response->json();
            $generation->update([
                'pixelforge_id' => $remote['id'],
                'status' => Generation::STATUS_QUEUED,
                'submitted_at' => now(),
            ]);

            return;
        }

        $error = $response->json('error') ?? [];
        $code = $error['code'] ?? 'unknown_error';

        switch ($code) {
            // Too many requests: wait out the Retry-After we were given.
            case 'rate_limit_exceeded':
            case 'concurrency_limit_exceeded':
            case 'daily_limit_exceeded':
            case 'overloaded':
                $retryAfter = (int) ($response->header('Retry-After') ?: 5);
                $this->release(min($retryAfter, 60));

                return;

            // The prompt was rejected; nothing was created or billed.
            case 'content_policy_violation':
                app(\App\Services\GenerationService::class)->markSubmissionFailed(
                    $generation,
                    $code,
                    $error['message'] ?? 'The prompt was rejected by Pixelforge.',
                );

                return;

            default:
                // A real bug or a change in the API: log it, don't hide it.
                Log::error('Pixelforge submit failed permanently', [
                    'generation_id' => $generation->id,
                    'status' => $response->status(),
                    'error' => $error,
                ]);
                app(\App\Services\GenerationService::class)->markSubmissionFailed(
                    $generation,
                    $code,
                    $error['message'] ?? 'Pixelforge rejected the request.',
                );
        }
    }
}
