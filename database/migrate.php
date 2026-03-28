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
// ── Add reset token columns to users (safe — checks first) ──────
if ($schema->hasTable('users')) {
    if (!$schema->hasColumn('users', 'reset_token')) {
        $schema->table('users', function (Blueprint $table) {
            $table->string('reset_token', 64)->nullable();
            $table->timestamp('reset_token_expiry')->nullable();
        });
    }
}// ── probe_events ─────────────────────────────────────────────────
if (!$schema->hasTable('probe_events')) {
    $schema->create('probe_events', function (Blueprint $table) {
        $table->id();
        $table->string('brand');
        $table->string('product')->nullable();
        $table->string('category');
        $table->string('status');
        $table->string('email')->nullable();
        $table->integer('dsov')->nullable();
        $table->string('source')->default('beta');
        $table->timestamps();
    });
}
// ── agent_probes ─────────────────────────────────────────────────
if (!$schema->hasTable('agent_probes')) {
    $schema->create('agent_probes', function (Blueprint $table) {
        $table->id();
        $table->string('brand');
        $table->string('category');
        $table->string('vertical')->nullable();
        $table->integer('dsov_score');
        $table->string('band');
        $table->integer('oai_score')->nullable();
        $table->integer('pplx_score')->nullable();
        $table->boolean('t1_validated')->default(false);
        $table->boolean('t1_present_oai')->default(false);
        $table->boolean('t1_present_pplx')->default(false);
        $table->boolean('t2_survives')->default(false);
        $table->boolean('t3_survives')->default(false);
        $table->boolean('t4_wins')->default(false);
        $table->boolean('oai_wins_t4')->default(false);
        $table->boolean('pplx_wins_t4')->default(false);
        $table->string('displacement_turn')->nullable();
        $table->json('t2_competitors')->nullable();
        $table->string('t4_winner')->nullable();
        $table->bigInteger('rar_annual')->nullable();
        $table->bigInteger('rar_monthly')->nullable();
        $table->bigInteger('revenue_used')->nullable();
        $table->string('contact_email')->nullable();
        $table->string('contact_seniority')->nullable();
        $table->string('contact_state')->nullable();
        $table->string('contact_company')->nullable();
        $table->boolean('email_sent')->default(false);
        $table->timestamp('email_sent_at')->nullable();
        $table->string('email_type')->nullable();
        $table->string('source')->default('agent_paul');
        $table->boolean('is_repeat')->default(false);
        $table->unsignedBigInteger('previous_probe_id')->nullable();
        $table->string('probe_version')->default('v1');
        $table->timestamps();
        $table->index('brand');
        $table->index('category');
        $table->index('band');
        $table->index('displacement_turn');
        $table->index('source');
        $table->index('created_at');
    });
}
