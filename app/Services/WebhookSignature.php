<?php

namespace App\Services;

use Illuminate\Http\Request;

/**
 * Verifies Pixelforge webhook signatures.
 *
 * The signature is hex HMAC-SHA256 of "<timestamp>.<raw body>" keyed with the
 * key's signing secret. Compare in constant time and reject stale timestamps
 * so captured requests can't be replayed later.
 */
class WebhookSignature
{
    public const MAX_AGE_SECONDS = 300;

    public static function verify(Request $request, string $secret): bool
    {
        $header = (string) $request->header('Pixelforge-Signature');

        $parts = [];
        foreach (explode(',', $header) as $piece) {
            [$key, $value] = array_pad(explode('=', $piece, 2), 2, '');
            $parts[$key] = $value;
        }

        $timestamp = $parts['t'] ?? '';
        $signature = $parts['v1'] ?? '';

        if ($timestamp === '' || $signature === '' || ! ctype_digit($timestamp)) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > self::MAX_AGE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }
}
