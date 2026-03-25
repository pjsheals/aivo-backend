<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

$schema = Capsule::schema();

// ── users ────────────────────────────────────────────────────────
if (!$schema->hasTable('users')) {
    $schema->create('users', function (Blueprint $table) {
        $table->id();
        $table->string('email')->unique();
        $table->string('name')->nullable();
        $table->string('company')->nullable();
        $table->string('plan')->default('free'); // free|growth|pro|agency
        $table->boolean('beta_access')->default(false);
        $table->string('stripe_customer_id')->nullable()->unique();
        $table->string('stripe_subscription_id')->nullable();
        $table->string('probe_brand')->nullable();
        $table->string('probe_category')->nullable();
        $table->integer('tests_used')->default(0);
        $table->string('tests_month')->nullable(); // YYYY-MM
        $table->timestamp('upgraded_at')->nullable();
        $table->timestamps();
    });
}

// ── subscriptions ────────────────────────────────────────────────
if (!$schema->hasTable('subscriptions')) {
    $schema->create('subscriptions', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained()->onDelete('cascade');
        $table->string('stripe_subscription_id')->unique();
        $table->string('stripe_price_id');
        $table->string('plan'); // growth|pro|agency
        $table->string('status'); // active|canceled|past_due|trialing
        $table->timestamp('current_period_start')->nullable();
        $table->timestamp('current_period_end')->nullable();
        $table->timestamp('canceled_at')->nullable();
        $table->timestamps();
    });
}

// ── diagnostic_runs ──────────────────────────────────────────────
if (!$schema->hasTable('diagnostic_runs')) {
    $schema->create('diagnostic_runs', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained()->onDelete('cascade');
        $table->string('brand');
        $table->string('category')->nullable();
        $table->integer('coda_score')->nullable();
        $table->integer('psos_score')->nullable();
        $table->json('results')->nullable();
        $table->timestamps();
    });
}

// ── stripe_events (idempotency log) ──────────────────────────────
if (!$schema->hasTable('stripe_events')) {
    $schema->create('stripe_events', function (Blueprint $table) {
        $table->id();
        $table->string('stripe_event_id')->unique();
        $table->string('event_type');
        $table->boolean('processed')->default(false);
        $table->timestamps();
    });
}
