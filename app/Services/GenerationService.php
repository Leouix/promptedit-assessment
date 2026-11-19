<?php

namespace App\Services;

use App\Jobs\SubmitGenerationJob;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The single place where a local generation moves through its lifecycle.
 *
 * Everything funnels through applyRemoteUpdate(), whether the new information
 * arrives from a webhook or from polling, so the state machine and the
 * "charge exactly once" rule live in one spot.
 */
class GenerationService
{
    public function __construct(private PricingService $pricing)
    {
    }

    /**
     * Create the local record (status: pending) and hand submission to a
     * queued job. The row exists before we call Pixelforge so that a webhook
     * racing the submit response can find it via metadata.local_id.
     */
    public function createForUser(User $user, array $validated): Generation
    {
        $estimate = Money::applyMarkup(
            $this->pricing->estimateProviderCost(
                $validated['prompt'],
                $validated['size'],
                $validated['quality'],
                count($validated['reference_images'] ?? []),
            )
        );

        $generation = Generation::create([
            'id' => $id = (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $user->id,
            'prompt' => $validated['prompt'],
            'size' => $validated['size'],
            'quality' => $validated['quality'],
            'reference_images' => $validated['reference_images'] ?? [],
            'estimate_cost' => $estimate,
            'status' => Generation::STATUS_PENDING,
        ]);

        // The uuid doubles as the Pixelforge Idempotency-Key and the
        // metadata.local_id, so a retry after "overloaded" can never create
        // two generations, and any webhook can find us.
        SubmitGenerationJob::dispatch($id);

        return $generation;
    }

    /**
     * Merge a Pixelforge generation object (from a webhook or a poll) into
     * our record. Statuses only move forward; succeeded and failed are final.
     *
     * Charging happens exactly once, guarded by the row lock and the final
     * status check, even when both a duplicate webhook and a poll race here.
     */
    public function applyRemoteUpdate(Generation $generation, array $remote): void
    {
        DB::transaction(function () use ($generation, $remote) {
            $generation = Generation::whereKey($generation->getKey())->lockForUpdate()->firstOrFail();

            if ($generation->isFinal()) {
                return; // Never move a generation back from succeeded/failed.
            }

            $updates = [
                'pixelforge_id' => $generation->pixelforge_id ?? $remote['id'],
                'submitted_at' => $generation->submitted_at ?? now(),
            ];

            match ($remote['status']) {
                Generation::STATUS_QUEUED => $updates['status'] = $generation->status === Generation::STATUS_PENDING
                    ? Generation::STATUS_QUEUED
                    : $generation->status,
                Generation::STATUS_PROCESSING => $updates['status'] = Generation::STATUS_PROCESSING,
                Generation::STATUS_SUCCEEDED => $this->applySucceeded($generation, $remote, $updates),
                Generation::STATUS_FAILED => $this->applyFailed($remote, $updates),
                default => throw new RuntimeException("Unknown remote status: {$remote['status']}"),
            };

            $generation->update($updates);
        });
    }

    private function applySucceeded(Generation $generation, array $remote, array &$updates): void
    {
        $usage = $remote['usage'] ?? null;
        $rates = $remote['rates'] ?? null;

        if ($usage === null || $rates === null) {
            throw new RuntimeException('Succeeded generation without usage/rates.');
        }

        $providerCost = $this->computeProviderCost($usage, $rates);
        $chargedCost = Money::applyMarkup($providerCost);

        $updates['status'] = Generation::STATUS_SUCCEEDED;
        $updates['provider_cost'] = $providerCost;
        $updates['charged_cost'] = $chargedCost;
        $updates['completed_at'] = $remote['completed_at'] ?? now();
        $updates['image_url'] = $remote['image_url'] ?? null;
        $updates['image_url_expires_at'] = $remote['image_url_expires_at'] ?? null;

        // Charge the user exactly once, in the same transaction.
        $user = $generation->user()->lockForUpdate()->firstOrFail();
        $user->addCredits(
            Money::multiply($chargedCost, '-1'),
            CreditTransaction::TYPE_CHARGE,
            $generation->id,
        );
    }

    private function applyFailed(array $remote, array &$updates): void
    {
        $updates['status'] = Generation::STATUS_FAILED;
        $updates['completed_at'] = $remote['completed_at'] ?? now();
        $updates['error'] = $remote['error'] ?? [
            'code' => 'generation_failed',
            'message' => 'The generation failed. You were not charged.',
        ];
    }

    /**
     * Actual provider cost from the usage Pixelforge reports, at the rates
     * the generation was billed at. Exact decimal math, never a float.
     */
    private function computeProviderCost(array $usage, array $rates): string
    {
        $cost = '0';
        foreach ([
            'input_text_tokens' => 'input_text_tokens',
            'input_image_tokens' => 'input_image_tokens',
            'output_tokens' => 'output_tokens',
            'reasoning_tokens' => 'reasoning_tokens',
        ] as $usageKey => $rateKey) {
            $cost = Money::add($cost, Money::tokenCost((int) $usage[$usageKey], $rates[$rateKey]));
        }

        return $cost;
    }

    /**
     * Submit failed for a reason Pixelforge will not retry for us
     * (rejected prompt, exhausted attempts). Nothing is ever charged.
     */
    public function markSubmissionFailed(Generation $generation, string $code, string $message): void
    {
        DB::transaction(function () use ($generation, $code, $message) {
            $generation = Generation::whereKey($generation->getKey())->lockForUpdate()->firstOrFail();

            if ($generation->isFinal()) {
                return;
            }

            $generation->update([
                'status' => Generation::STATUS_FAILED,
                'error' => ['code' => $code, 'message' => $message],
                'completed_at' => now(),
            ]);
        });
    }
}
