<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Services\GenerationService;
use App\Services\Money;
use App\Services\PricingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class GenerationController extends Controller
{
    public function __construct(private GenerationService $generations, private PricingService $pricing)
    {
    }

    public function index()
    {
        return Inertia::render('Generations/Index', [
            'generations' => auth()->user()->generations()
                ->select(['id', 'prompt', 'size', 'quality', 'reference_images', 'status',
                    'image_url', 'estimate_cost', 'provider_cost', 'charged_cost', 'error', 'created_at'])
                ->limit(100)
                ->get(),
        ]);
    }

    public function create()
    {
        return Inertia::render('Generations/Create', [
            'pricing' => $this->pricing->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['required', 'in:1024x1024,1536x1024,1024x1536'],
            'quality' => ['required', 'in:low,medium,high'],
            'reference_images' => ['nullable', 'array', 'max:4'],
            'reference_images.*' => ['url:http,https'],
        ]);

        $estimate = $this->pricing->estimateProviderCost(
            $validated['prompt'], $validated['size'], $validated['quality'],
            count($validated['reference_images'] ?? []),
        );
        $user = $request->user();

        // Block generation the user can't afford: we compare against the
        // estimate, because the actual cost is only known on completion.
        if (bccomp($user->credits, $estimate, 12) < 0) {
            return back()->withErrors([
                'credits' => 'Not enough credits: this generation is estimated at $'.
                    \App\Services\Money::display(\App\Services\Money::applyMarkup($estimate)).
                    ', you have $'.\App\Services\Money::display($user->credits).'. Buy credits to continue.',
            ]);
        }

        $generation = $this->generations->createForUser($user, $validated);

        return redirect()->route('generations.index')
            ->with('success', 'Generation submitted.');
    }

    public function adminReport()
    {
        Gate::authorize('view-admin');

        // Pixelforge bills a generation on the UTC day it completed, so
        // profit per day uses that day too and reconciles with GET /v1/usage.
        // We sum with bcmath (not SQL SUM): SQLite would return floats.
        $generations = \App\Models\Generation::query()
            ->whereNotNull('charged_cost')
            ->with('user:id,name')
            ->get();

        $grouped = $generations->groupBy(function (\App\Models\Generation $g) {
            return $g->completed_at->copy()->utc()->toDateString().'|'.$g->user_id.'|'.$g->user->name;
        });

        $rows = $grouped->map(function ($dayGroup, $key) {
            [$day, $userId, $userName] = explode('|', $key);

            $creditsSpent = '0';
            $providerCost = '0';
            foreach ($dayGroup as $g) {
                $creditsSpent = Money::add($creditsSpent, $g->charged_cost);
                $providerCost = Money::add($providerCost, $g->provider_cost);
            }

            return [
                'completed_day' => $day,
                'user_id' => (int) $userId,
                'user_name' => $userName,
                'credits_spent' => $creditsSpent,
                'provider_cost' => $providerCost,
                'profit' => Money::sub($creditsSpent, $providerCost),
            ];
        })->sortByDesc('completed_day')->values();

        return Inertia::render('Generations/Admin', [
            'rows' => $rows,
        ]);
    }
}
