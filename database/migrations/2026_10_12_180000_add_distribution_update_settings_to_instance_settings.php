<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('instance_settings', 'distribution_channel')) {
                $table->string('distribution_channel')->default('stable');
            }
            if (! Schema::hasColumn('instance_settings', 'is_distribution_auto_update_enabled')) {
                $table->boolean('is_distribution_auto_update_enabled')->default(false);
            }
            if (! Schema::hasColumn('instance_settings', 'distribution_backup_max_age_hours')) {
                $table->unsignedInteger('distribution_backup_max_age_hours')->default(24);
            }
        });
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            foreach (['distribution_channel', 'is_distribution_auto_update_enabled', 'distribution_backup_max_age_hours'] as $column) {
                if (Schema::hasColumn('instance_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
