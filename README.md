# Pixelforge Image Studio

A take-home assessment for PromptEdit (Senior Product Engineer): a Laravel +
Vue + Inertia app where users generate images through the fictional
[Pixelforge](https://pixelforge.promptedit.dev) API and are charged for
exactly what they generate.

## What it does

- **Generate.** Users write a prompt, pick a size (1024x1024 / 1536x1024 /
  1024x1536) and quality (low / medium / high), and can add up to 4 optional
  reference image URLs. Before submitting they see an **estimate** of what it
  will cost them.
- **Pricing.** Users pay Pixelforge's cost **plus a 25% markup**. The estimate
  is computed from what is knowable up front: `ceil(chars/4)` text tokens
  (±15%), 765 tokens per reference image, exact output tokens by quality/size,
  and the midpoint of the reasoning-token range for the quality. Once a
  generation succeeds, the **actual** cost is recorded from the generation's
  real token usage and the rates it was billed at, and the user is charged
  exactly that (`cost × 1.25`). Failed generations are free.
- **Money math.** No floats anywhere. All money is exact decimal arithmetic
  (bcmath in PHP, stored in `DECIMAL(24,12)` columns); Pixelforge reports
  costs to 9 decimals, and the 25% markup stays exact too.
- **History.** Live status (pending → queued → processing → succeeded/failed,
  polled every 4s while anything is in flight), estimate, actual cost
  (charged + provider), image, and a clear error message when something
  fails.
- **Webhooks.** `POST /webhooks/pixelforge` verifies the HMAC-SHA256
  signature (`Pixelforge-Signature: t=…,v1=…`) against the raw body in
  constant time and rejects timestamps older than 5 minutes. Events are
  deduplicated by their `evt_…` id (unique index — a redelivery answers 200
  and records nothing). A forward-only state machine absorbs out-of-order
  events: nothing ever moves back from succeeded/failed, and charging is
  guarded by a row lock, so a generation can never be charged twice no matter
  how many duplicate events or polls race each other.
- **Resilience.** Submissions run through a queued job that retries on
  `429`/`503` respecting `Retry-After` (capped at 5 attempts) and uses the
  generation's UUID as the Pixelforge `Idempotency-Key`, so a retry after
  "overloaded" can never create a second generation. A rejected prompt
  (`[blocked]`) fails locally without any charge. A scheduled command
  (`generations:poll-stale`, every minute) polls Pixelforge for generations
  that never settled — webhooks are not guaranteed delivery.
- **Stretch: admin report.** `/admin/report` (admin users only) shows credit
  spend, provider costs and profit per UTC day (the day each generation
  completed, matching how `GET /v1/usage` bills) and per user.
- **Stretch: balances.** Every user has a credit balance (starts with a $5
  welcome credit), a fake "Buy credits" button (+$10, no real payments), and
  generating is blocked when the balance is below the estimate.

## Testing the error paths

Pixelforge's prompt keywords work in the app exactly as documented:

- `[blocked]` → rejected at submission, nothing created or billed.
- `[overloaded]` → 503 at submission, the queued job retries with backoff.
- `[fail]` → accepted, then fails; the user is never charged.
- `[slow]` → 60–90s processing; polling picks it up if webhooks are delayed.
- `[duplicate]` → every webhook arrives twice; the second is deduplicated.

## Local setup

```bash
composer install
cp .env.example .env       # add PIXELFORGE_API_KEY (and webhook secret once set)
php artisan key:generate
touch database/database.sqlite
php artisan migrate
npm install && npm run dev # or npm run build
php artisan queue:work     # separate terminal
php artisan schedule:work  # separate terminal (polling fallback)
php artisan serve
```

For webhooks during development, expose the app with a tunnel (ngrok,
Expose, Cloudflare Tunnel) and point the key's webhook URL at
`http://<your-host>/webhooks/pixelforge`. The signing secret goes into
`PIXELFORGE_WEBHOOK_SECRET` in `.env`.

## Production setup (Docker)

```bash
cp .env.example .env
# configure: APP_URL, APP_KEY (php artisan key:generate), PIXELFORGE_API_KEY,
# PIXELFORGE_WEBHOOK_SECRET
docker compose build
docker compose up -d
```

The app container creates the SQLite database, runs migrations and publishes
the Vite assets on boot; nginx serves on port 8000; `queue` runs the worker
and `scheduler` runs the polling fallback every minute.

## Architecture notes

- `app/Services/GenerationService.php` — the only place lifecycle state
  changes; owns the forward-only state machine and the charge-once rule.
- `app/Services/Money.php` — exact decimal helpers (bcmath, scale 12).
- `app/Services/PricingService.php` — reads `/v1/pricing` (rates change; we
  never hardcode them) and computes estimates.
- `app/Jobs/SubmitGenerationJob.php` — submission with idempotency key,
  rate-limit/overload retries, and local failure for rejected prompts.
- `app/Jobs/HandleWebhookEventJob.php` — applies a verified, deduplicated
  event to the generation.
- `app/Console/Commands/PollStaleGenerations.php` — polling safety net.
- Tests: `php artisan test` (45 tests) cover exact money math, duplicate and
  out-of-order webhooks, late webhooks racing the submit response, failed
  generations being free, and rejected submissions.
