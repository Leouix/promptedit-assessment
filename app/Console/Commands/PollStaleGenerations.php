<?php

namespace App\Console\Commands;

use App\Models\Generation;
use App\Services\GenerationService;
use App\Services\PixelforgeClient;
use Illuminate\Console\Command;

/**
 * Safety net for the "delivery isn't guaranteed" case: if a generation has
 * been waiting in a non-final state with no webhook for a while, ask
 * Pixelforge directly. This is also the only source of truth for webhooks
 * that never arrive at all.
 */
class PollStaleGenerations extends Command
{
    protected $signature = 'generations:poll-stale';

    protected $description = 'Poll Pixelforge for generations that have not settled via webhooks';

    public function handle(PixelforgeClient $client, GenerationService $service): int
    {
        // Stale threshold: [slow] generations process for up to 90 seconds,
        // and a settled generation usually reports long before this.
        $staleBefore = now()->subSeconds(120);

        $stale = Generation::query()
            ->whereIn('status', [Generation::STATUS_PENDING, Generation::STATUS_QUEUED, Generation::STATUS_PROCESSING])
            ->where(function ($query) use ($staleBefore) {
                $query->whereNull('submitted_at')->where('created_at', '<', $staleBefore)
                    ->orWhere(function ($query) use ($staleBefore) {
                        $query->whereNotNull('submitted_at')
                            ->where('updated_at', '<', $staleBefore);
                    });
            })
            ->get();

        foreach ($stale as $generation) {
            if ($generation->pixelforge_id === null) {
                // Still no id after this long: submission is lost or stuck.
                // Re-dispatch the submit; its Idempotency-Key makes that safe.
                \App\Jobs\SubmitGenerationJob::dispatch($generation->id);

                continue;
            }

            $response = $client->getGeneration($generation->pixelforge_id);

            if ($response->successful()) {
                $service->applyRemoteUpdate($generation, $response->json());
            }
        }

        $this->info("Polled {$stale->count()} stale generation(s).");

        return self::SUCCESS;
    }
}
