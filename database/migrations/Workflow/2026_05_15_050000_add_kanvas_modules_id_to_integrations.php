<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            // Cross-DB reference to ecosystem.kanvas_modules.id — no FK constraint.
            $table->unsignedBigInteger('kanvas_modules_id')->nullable()->after('apps_id');
            $table->index('kanvas_modules_id', 'idx_integrations_kanvas_module');
        });
    }

    public function down(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->dropIndex('idx_integrations_kanvas_module');
            $table->dropColumn('kanvas_modules_id');
        });
    }
};
