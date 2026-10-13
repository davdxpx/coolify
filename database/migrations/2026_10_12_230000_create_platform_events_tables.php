<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('platform_events')) {
            Schema::create('platform_events', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
                $table->string('type');
                $table->string('subject_type');
                $table->string('subject_uuid')->nullable();
                $table->string('correlation_id')->nullable();
                $table->json('data');
                $table->timestamp('occurred_at');
                $table->timestamps();

                $table->index(['team_id', 'id']);
                $table->index('occurred_at');
            });
        }

        if (! Schema::hasTable('event_subscriptions')) {
            Schema::create('event_subscriptions', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->foreignId('team_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->text('url');
                $table->text('secret');
                $table->json('event_types');
                $table->boolean('is_enabled')->default(true);
                $table->unsignedInteger('consecutive_failures')->default(0);
                $table->string('disabled_reason')->nullable();
                $table->timestamp('last_delivery_at')->nullable();
                $table->unsignedSmallInteger('last_response_status')->nullable();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('event_deliveries')) {
            Schema::create('event_deliveries', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->foreignId('platform_event_id')->constrained()->cascadeOnDelete();
                $table->foreignId('event_subscription_id')->constrained()->cascadeOnDelete();
                $table->string('state')->default('pending');
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->timestamp('next_attempt_at')->nullable();
                $table->unsignedSmallInteger('response_status')->nullable();
                $table->string('last_error')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamps();

                $table->unique(['platform_event_id', 'event_subscription_id']);
                $table->index(['state', 'next_attempt_at']);
            });
        }

        if (! Schema::hasTable('backup_restore_evidence')) {
            Schema::create('backup_restore_evidence', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->foreignId('team_id')->constrained()->cascadeOnDelete();
                $table->string('database_type');
                $table->unsignedBigInteger('database_id');
                $table->string('backup_execution_uuid')->nullable();
                $table->string('result');
                $table->string('method');
                $table->text('notes')->nullable();
                $table->timestamp('verified_at');
                $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['database_type', 'database_id', 'verified_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_restore_evidence');
        Schema::dropIfExists('event_deliveries');
        Schema::dropIfExists('event_subscriptions');
        Schema::dropIfExists('platform_events');
    }
};
