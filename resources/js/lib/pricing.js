/**
 * Estimate math, mirrored from App\Services\PricingService.
 *
 * Everything happens in integers of "nanodollars" (1e-9 USD) where possible
 * so fractions of a cent stay exact; the 1.25 markup ends in .25, which
 * doubles represent exactly.
 */

export function textTokens(prompt) {
    // Pixelforge: about one token per 4 Unicode characters.
    return Math.ceil([...prompt].length / 4);
}

export function estimateProviderCost(pricing, prompt, size, quality, referenceImageCount) {
    const t = pricing.tokens;
    const r = pricing.rates;

    const tokens = {
        input_text_tokens: textTokens(prompt),
        input_image_tokens: t.per_reference_image * referenceImageCount,
        output_tokens: (t.output[quality] || {})[size] || 0,
        reasoning_tokens: Math.round(
            (t.reasoning[quality].min + t.reasoning[quality].max) / 2,
        ),
    };

    let nano = 0;
    for (const key of Object.keys(tokens)) {
        // rate in dollars per 1M tokens -> nano per token: rate * 1e9 / 1e6
        const nanoPerToken = parseFloat(r[key]) * 1000;
        nano += tokens[key] * nanoPerToken;
    }

    return {
        tokens,
        providerCost: nano / 1e9,
        userCost: (nano / 1e9) * 1.25,
    };
}

/** "$0.0524" — enough decimals to see fractions of a cent, no trailing zeros. */
export function formatUsd(amount, minDecimals = 4) {
    if (!isFinite(amount)) return '$0';
    const fixed = amount.toFixed(minDecimals);
    const trimmed = fixed.replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '');
    return '$' + trimmed;
}
