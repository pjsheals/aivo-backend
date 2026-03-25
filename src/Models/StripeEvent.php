<?php

declare(strict_types=1);

namespace Aivo\Models;

use Illuminate\Database\Eloquent\Model;

class StripeEvent extends Model
{
    protected $table = 'stripe_events';

    protected $fillable = [
        'stripe_event_id',
        'event_type',
        'processed',
    ];

    protected $casts = [
        'processed' => 'boolean',
    ];
}
