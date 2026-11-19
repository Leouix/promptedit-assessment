<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Rates and token tables from GET /v1/pricing (no auth required).
 * Rates change, so we read them instead of hardcoding and cache briefly.
 */
class PricingService
{
    private const CACHE_KEY = 'pixelforge.pricing';

    public function get(): array
    {
        return Cache::remember(self::CACHE_KEY, 300, function () {
            $response = Http::get(config('services.pixelforge.base_url').'/pricing')->throw();

            return $response->json();
        });
    }

    public function rates(): array
    {
        return $this->get()['rates'];
    }

    /**
     * Estimate a generation's provider cost from what is knowable up front.
     *
     * - input text: ceil(characters / 4) — the real count is within ±15%.
     * - input image: exactly 765 per reference image.
     * - output: exact, by quality and size.
     * - reasoning: we use the midpoint of the quality's range.
     */
    public function estimateProviderCost(string $prompt, string $size, string $quality, int $referenceImageCount): string
    {
        $pricing = $this->get();
        $tokens = $pricing['tokens'];
        $rates = $pricing['rates'];

        $textTokens = (int) ceil(mb_strlen($prompt) / $tokens['characters_per_text_token']);
        $imageTokens = $tokens['per_reference_image'] * $referenceImageCount;
        $outputTokens = $tokens['output'][$quality][$size] ?? 0;
        $reasoningRange = $tokens['reasoning'][$quality];
        $reasoningTokens = (int) round(($reasoningRange['min'] + $reasoningRange['max']) / 2);

        $cost = '0';
        $cost = Money::add($cost, Money::tokenCost($textTokens, $rates['input_text_tokens']));
        $cost = Money::add($cost, Money::tokenCost($imageTokens, $rates['input_image_tokens']));
        $cost = Money::add($cost, Money::tokenCost($outputTokens, $rates['output_tokens']));
        $cost = Money::add($cost, Money::tokenCost($reasoningTokens, $rates['reasoning_tokens']));

        return $cost;
    }
}
