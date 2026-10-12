<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('deployment_freezes')) {
            Schema::create('deployment_freezes', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->foreignId('team_id')->constrained()->cascadeOnDelete();
                $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
                $table->foreignId('environment_id')->nullable()->constrained()->cascadeOnDelete();
                $table->string('reason');
                $table->timestamp('starts_at');
                $table->timestamp('ends_at')->nullable();
                $table->boolean('allow_rollbacks')->default(true);
                $table->boolean('allow_override')->default(true);
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('ended_at')->nullable();
                $table->foreignId('ended_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['team_id', 'starts_at']);
            });
        }

        Schema::table('application_deployment_queues', function (Blueprint $table) {
            if (! Schema::hasColumn('application_deployment_queues', 'artifacts')) {
                $table->json('artifacts')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('application_deployment_queues', function (Blueprint $table) {
            if (Schema::hasColumn('application_deployment_queues', 'artifacts')) {
                $table->dropColumn('artifacts');
            }
        });
        Schema::dropIfExists('deployment_freezes');
    }
};
