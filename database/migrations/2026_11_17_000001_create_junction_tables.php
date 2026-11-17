<?php

/**
 * Creates Junction's four tables: the endpoints an administrator adds, the
 * events waiting to be sent, each delivery to an endpoint, and each try.
 *
 * @package   KraftySprouts\Junction
 * @author    Krafty Sprouts Media, LLC
 * @copyright 2026 Krafty Sprouts Media, LLC
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Creates the tables. Teme never runs down(), so there is none.
     *
     * @since 1.0.0
     */
    public function up(): void
    {
        Schema::create('junction_endpoints', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('name', 80);
            $table->string('url', 2048);
            $table->boolean('enabled')->default(true);
            $table->string('format', 20)->default('json');
            $table->unsignedSmallInteger('timeout')->default(10);
            $table->jsonb('events');
            $table->jsonb('other_events');
            $table->jsonb('types');
            $table->jsonb('categories');
            $table->jsonb('tags');
            $table->jsonb('fields');
            $table->text('secret');
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestampTz('paused_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('junction_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_id', 26)->unique();
            $table->string('event', 100);
            $table->json('data');
            $table->timestampTz('occurred_at');
        });

        Schema::create('junction_deliveries', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('endpoint_id', 26)->index();
            $table->string('event', 100);
            $table->string('event_id', 26);
            $table->json('payload');
            $table->string('status', 12)->default('waiting');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'next_attempt_at']);
        });

        Schema::create('junction_attempts', function (Blueprint $table): void {
            $table->id();
            $table->string('delivery_id', 26)->index();
            $table->string('endpoint_id', 26);
            $table->string('endpoint_name', 80);
            $table->string('event', 100);
            $table->boolean('ok');
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('error', 300)->nullable();
            $table->string('reply', 500)->default('');
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestampTz('created_at')->index();
        });
    }
};
