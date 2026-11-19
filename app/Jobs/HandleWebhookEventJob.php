<?php

namespace App\Jobs;

use App\Models\WebhookEvent;
use App\Services\GenerationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Applies one stored webhook event to a generation.
 *
 * Deduplication already happened at the controller (unique event_id), but
 * events still arrive more than once with *different* event ids and out of
 * order; applyRemoteUpdate's forward-only state machine absorbs both.
 */
class HandleWebhookEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;

    public int $backoff = 10;

    public function __construct(public int $eventId)
    {
    }

    public function handle(GenerationService $service): void
    {
        $event = WebhookEvent::findOrFail($this->eventId);

        if ($event->processed_at !== null) {
            return;
        }

        $remote = $event->payload['data']['object'] ?? null;
        if ($remote === null) {
            $event->update(['processed_at' => now()]);

            return; // e.g. "ping" — nothing to apply.
        }

        $generation = $this->findGeneration($remote);
        if ($generation === null) {
            // A webhook can beat the submit response (though our row is
            // created before submit). Give the queue a few chances.
            if ($this->attempts() >= 5) {
                Log::warning('Webhook event for unknown generation', [
                    'event_id' => $event->event_id,
                    'remote_id' => $remote['id'] ?? null,
                ]);
                $event->update(['processed_at' => now()]);

                return;
            }

            $this->release($this->backoff * $this->attempts());

            return;
        }

        $service->applyRemoteUpdate($generation, $remote);
        $event->update(['processed_at' => now()]);
    }

    /**
     * Match by the local_id we put in metadata, else by Pixelforge's id.
     */
    private function findGeneration(array $remote): ?\App\Models\Generation
    {
        $localId = $remote['metadata']['local_id'] ?? null;

        if ($localId !== null) {
            $generation = \App\Models\Generation::find($localId);
            if ($generation !== null) {
                return $generation;
            }
        }

        return \App\Models\Generation::where('pixelforge_id', $remote['id'] ?? null)->first();
    }
}
