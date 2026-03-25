<?php

declare(strict_types=1);

namespace Aivo\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $table = 'users';

    protected $fillable = [
        'email',
        'name',
        'company',
        'plan',
        'beta_access',
        'stripe_customer_id',
        'stripe_subscription_id',
        'probe_brand',
        'probe_category',
        'tests_used',
        'tests_month',
        'upgraded_at',
    ];

    protected $casts = [
        'beta_access'  => 'boolean',
        'upgraded_at'  => 'datetime',
        'tests_used'   => 'integer',
    ];

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function diagnosticRuns()
    {
        return $this->hasMany(DiagnosticRun::class);
    }

    public function activeSubscription()
    {
        return $this->subscriptions()
            ->whereIn('status', ['active', 'trialing'])
            ->latest()
            ->first();
    }

    public function isUnlimited(): bool
    {
        return $this->beta_access || in_array($this->plan, ['growth', 'pro', 'agency']);
    }
}
