<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Services\GenerationService;
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

        $rows = \DB::table('generations as g')
            ->join('users as u', 'u.id', '=', 'g.user_id')
            ->whereNotNull('g.charged_cost')
            ->groupBy('completed_day', 'u.id', 'u.name')
            ->orderByDesc('completed_day')
            ->get([
                // Pixelforge bills a generation on the UTC day it completed,
                // so profit per day uses that day too and reconciles with
                // GET /v1/usage.
                \DB::raw("strftime('%Y-%m-%d', completed_at) as completed_day"),
                'u.id as user_id', 'u.name as user_name',
                \DB::raw("SUM(g.charged_cost) as credits_spent"),
                \DB::raw("SUM(g.provider_cost) as provider_cost"),
                \DB::raw("SUM(g.charged_cost - g.provider_cost) as profit"),
            ]);

        return Inertia::render('Generations/Admin', [
            'rows' => $rows,
        ]);
    }
}
