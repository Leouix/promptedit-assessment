<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Generation extends Model
{
    /** Not final, waiting to be submitted to Pixelforge. */
    public const STATUS_PENDING = 'pending';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    /** Final states. Once a generation is here, it never moves back. */
    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id', 'user_id', 'prompt', 'size', 'quality', 'reference_images', 'status',
        'pixelforge_id', 'image_url', 'image_url_expires_at',
        'estimate_cost', 'provider_cost', 'charged_cost',
        'error', 'submitted_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'reference_images' => 'array',
            'error' => 'array',
            'estimate_cost' => 'decimal:12',
            'provider_cost' => 'decimal:12',
            'charged_cost' => 'decimal:12',
            'image_url_expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isFinal(): bool
    {
        return in_array($this->status, [self::STATUS_SUCCEEDED, self::STATUS_FAILED], true);
    }
}
