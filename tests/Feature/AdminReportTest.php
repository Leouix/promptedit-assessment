<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_report_sums_money_exactly(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();

        // Two charges: 0.0807375 + 0.081428125; provider 0.06459 + 0.0651425.
        $this->makeGeneration($user, '0.080737500000', '0.064590000000');
        $this->makeGeneration($user, '0.081428125000', '0.065142500000');

        $response = $this->actingAs($admin)->get('/admin/report');

        $response->assertOk();
        // Inertia props live in the data-page attribute of the HTML shell.
        preg_match('/data-page="([^"]+)"/', $response->getContent(), $m);
        $page = json_decode(html_entity_decode($m[1] ?? ''), true);
        $rows = collect($page['props']['rows']);
        $this->assertCount(1, $rows);

        $row = $rows->first();
        $this->assertSame('0.162165625000', $row['credits_spent']);
        $this->assertSame('0.129732500000', $row['provider_cost']);
        $this->assertSame('0.032433125000', $row['profit']);
    }

    public function test_non_admin_is_forbidden(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin/report')->assertForbidden();
    }

    private function makeGeneration(User $user, string $charged, string $provider): void
    {
        $g = Generation::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $user->id,
            'prompt' => 'p',
            'size' => '1024x1024',
            'quality' => 'medium',
            'estimate_cost' => '0.1',
            'status' => Generation::STATUS_SUCCEEDED,
            'provider_cost' => $provider,
            'charged_cost' => $charged,
            'completed_at' => now(),
        ]);
    }
}
