<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $columns = ['phase', 'phase_changed_at', 'phase_history', 'admitted_at', 'dispatched_at', 'started_at'];

    public function up(): void
    {
        Schema::table('application_deployment_queues', function (Blueprint $table) {
            if (! Schema::hasColumn('application_deployment_queues', 'phase')) {
                $table->string('phase')->nullable();
            }
            if (! Schema::hasColumn('application_deployment_queues', 'phase_changed_at')) {
                $table->timestamp('phase_changed_at')->nullable();
            }
            if (! Schema::hasColumn('application_deployment_queues', 'phase_history')) {
                $table->json('phase_history')->nullable();
            }
            if (! Schema::hasColumn('application_deployment_queues', 'admitted_at')) {
                $table->timestamp('admitted_at')->nullable();
            }
            if (! Schema::hasColumn('application_deployment_queues', 'dispatched_at')) {
                $table->timestamp('dispatched_at')->nullable();
            }
            if (! Schema::hasColumn('application_deployment_queues', 'started_at')) {
                $table->timestamp('started_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('application_deployment_queues', function (Blueprint $table) {
            foreach ($this->columns as $column) {
                if (Schema::hasColumn('application_deployment_queues', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
