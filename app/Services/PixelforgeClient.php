<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over the Pixelforge HTTP API. Knows nothing about our
 * generations or pricing — callers map responses onto our records.
 */
class PixelforgeClient
{
    public function createGeneration(array $payload, string $idempotencyKey): Response
    {
        return $this->request()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('/generations', $payload);
    }

    public function getGeneration(string $id): Response
    {
        return $this->request()->get("/generations/{$id}");
    }

    public function getUsage(string $startDate, string $endDate): Response
    {
        return $this->request()->get('/usage', [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(config('services.pixelforge.base_url'))
            ->acceptJson()
            ->withToken(config('services.pixelforge.api_key'))
            ->timeout(30)
            ->connectTimeout(10);
    }
}
