<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('release_groups')) {
            Schema::create('release_groups', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->foreignId('team_id')->constrained()->cascadeOnDelete();
                $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->text('description')->nullable();
                $table->boolean('is_enabled')->default(true);
                $table->string('failure_policy')->default('stop');
                $table->unsignedInteger('max_parallel')->default(2);
                $table->boolean('require_healthy')->default(true);
                $table->unsignedInteger('verification_timeout_seconds')->default(600);
                $table->json('preflight_policy')->nullable();
                $table->timestamps();

                $table->index(['team_id', 'environment_id']);
            });
        }

        if (! Schema::hasTable('release_group_members')) {
            Schema::create('release_group_members', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->foreignId('release_group_id')->constrained()->cascadeOnDelete();
                $table->string('resource_type');
                $table->unsignedBigInteger('resource_id');
                $table->string('role')->nullable();
                $table->unsignedInteger('phase')->default(1);
                $table->unsignedInteger('position')->default(0);
                $table->boolean('is_required')->default(true);
                $table->boolean('is_enabled')->default(true);
                $table->boolean('force_rebuild')->default(false);
                $table->json('depends_on')->nullable();
                $table->timestamps();

                $table->unique(['release_group_id', 'resource_type', 'resource_id']);
                $table->index(['resource_type', 'resource_id']);
            });
        }

        if (! Schema::hasTable('releases')) {
            Schema::create('releases', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->foreignId('release_group_id')->constrained()->cascadeOnDelete();
                $table->foreignId('team_id')->constrained()->cascadeOnDelete();
                $table->string('state')->index();
                $table->string('trigger');
                $table->string('idempotency_key')->nullable();
                $table->string('correlation_id');
                $table->string('requested_commit')->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('source_release_id')->nullable()->constrained('releases')->nullOnDelete();
                $table->json('policy_snapshot')->nullable();
                $table->json('preflight')->nullable();
                $table->json('acknowledged_checks')->nullable();
                $table->text('acknowledgement_reason')->nullable();
                $table->json('summary')->nullable();
                $table->text('failure_summary')->nullable();
                $table->text('attention_reason')->nullable();
                $table->timestamp('validated_at')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['release_group_id', 'idempotency_key']);
                $table->index(['team_id', 'state']);
                $table->index('correlation_id');
            });
        }

        if (! Schema::hasTable('release_members')) {
            Schema::create('release_members', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->foreignId('release_id')->constrained()->cascadeOnDelete();
                $table->foreignId('release_group_member_id')->nullable()->constrained()->nullOnDelete();
                $table->string('group_member_uuid');
                $table->string('resource_type');
                $table->unsignedBigInteger('resource_id');
                $table->string('resource_name');
                $table->unsignedInteger('phase')->default(1);
                $table->boolean('is_required')->default(true);
                $table->boolean('force_rebuild')->default(false);
                $table->json('depends_on')->nullable();
                $table->string('requested_commit')->nullable();
                $table->string('state')->index();
                $table->string('deployment_uuid')->nullable()->index();
                $table->string('deployment_status')->nullable();
                $table->string('actual_commit')->nullable();
                $table->string('image_digest')->nullable();
                $table->json('verification')->nullable();
                $table->text('failure_reason')->nullable();
                $table->string('note')->nullable();
                $table->unsignedInteger('attempts')->default(0);
                $table->timestamp('queued_at')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('deployed_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();

                $table->index(['release_id', 'state']);
                $table->index(['resource_type', 'resource_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('release_members');
        Schema::dropIfExists('releases');
        Schema::dropIfExists('release_group_members');
        Schema::dropIfExists('release_groups');
    }
};
