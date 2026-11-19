<?php

namespace App\Http\Controllers;

use App\Jobs\HandleWebhookEventJob;
use App\Models\WebhookEvent;
use App\Services\WebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PixelforgeWebhookController extends Controller
{
    /**
     * Pixelforge calls this for every status change. Any 2xx within 10s
     * counts as delivered, so: verify, store the event (unique event_id
     * dedupes redeliveries), then answer immediately — the actual work runs
     * in a queued job.
     */
    public function __invoke(Request $request)
    {
        $secret = config('services.pixelforge.webhook_secret');

        if ($secret === null || ! WebhookSignature::verify($request, $secret)) {
            Log::warning('Rejected Pixelforge webhook: bad signature', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'invalid_signature'], 400);
        }

        $payload = json_decode($request->getContent(), true);
        $eventId = $payload['id'] ?? null;
        $type = $payload['type'] ?? null;

        if (! is_string($eventId) || ! is_string($type)) {
            return response()->json(['error' => 'malformed_event'], 400);
        }

        try {
            WebhookEvent::create([
                'event_id' => $eventId,
                'type' => $type,
                'payload' => $payload,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Unique(event_id): the same event was delivered again. Answer
            // 200 so Pixelforge stops retrying — the first delivery wins,
            // nothing is recorded twice.
            return response()->noContent();
        }

        $stored = WebhookEvent::where('event_id', $eventId)->first();
        if ($stored !== null) {
            HandleWebhookEventJob::dispatch($stored->id);
        }

        return response()->noContent();
    }
}
