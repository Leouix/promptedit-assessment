<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditTransaction extends Model
{
    public const TYPE_WELCOME = 'welcome';

    public const TYPE_PURCHASE = 'purchase';

    public const TYPE_CHARGE = 'charge';

    protected $fillable = ['user_id', 'type', 'amount', 'generation_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
