<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('configuration_contracts')) {
            return;
        }

        Schema::create('configuration_contracts', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('application_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('required_keys');
            $table->json('optional_keys')->nullable();
            $table->string('source')->nullable();
            $table->string('source_revision')->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuration_contracts');
    }
};
