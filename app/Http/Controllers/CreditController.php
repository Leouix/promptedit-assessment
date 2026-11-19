<?php

namespace App\Http\Controllers;

use App\Models\CreditTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * "Buy credits" is a fake purchase: no real payments, we just top the
 * balance up and record it so the admin report stays honest.
 */
class CreditController extends Controller
{
    public const PURCHASE_AMOUNT = '10.00';

    public function buy(Request $request)
    {
        DB::transaction(function () use ($request) {
            $user = \App\Models\User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $user->addCredits(
                \App\Services\Money::normalize(self::PURCHASE_AMOUNT),
                CreditTransaction::TYPE_PURCHASE,
            );
        });

        return back()->with('success', 'Credits added.');
    }
}
