<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    private const CONNECTION = 'intelligence';

    public function up(): void
    {
        // Materialized-path column for nevadskiy/laravel-tree (AsTree). parent_project_id already
        // exists as the adjacency FK — the trait's getParentKeyName() override points at it.
        Schema::connection(self::CONNECTION)->table('nervous_system_projects', function (Blueprint $table) {
            $table->string('path')->nullable()->after('parent_project_id')->index();
        });
    }

    public function down(): void
    {
        Schema::connection(self::CONNECTION)->table('nervous_system_projects', function (Blueprint $table) {
            $table->dropColumn('path');
        });
    }
};
