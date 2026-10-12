<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('coolify_upgrades')) {
            return;
        }

        Schema::create('coolify_upgrades', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('distribution');
            $table->string('channel')->nullable();
            $table->string('trigger');
            $table->string('state')->index();
            $table->string('from_version')->nullable();
            $table->string('from_base_version')->nullable();
            $table->unsignedInteger('from_build')->nullable();
            $table->string('from_commit', 40)->nullable();
            $table->string('from_digest')->nullable();
            $table->string('from_latest_migration')->nullable();
            $table->string('to_version');
            $table->string('to_base_version');
            $table->unsignedInteger('to_build');
            $table->string('to_commit', 40);
            $table->string('to_image');
            $table->string('to_digest');
            $table->string('to_latest_migration')->nullable();
            $table->boolean('includes_migrations')->nullable();
            $table->json('preflight')->nullable();
            $table->json('acknowledged_checks')->nullable();
            $table->text('acknowledgement_reason')->nullable();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('message')->nullable();
            $table->string('latest_migration_after')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['state', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coolify_upgrades');
    }
};
