<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'intelligence';

    public function up(): void
    {
        if (! Schema::connection('intelligence')->hasColumn('nervous_system_tools', 'tool_category_id')) {
            Schema::table('nervous_system_tools', function (Blueprint $table) {
                $table->unsignedBigInteger('tool_category_id')->nullable()->after('category');
                $table->index('tool_category_id', 'idx_tools_category_id');
            });
        }

        // Backfill: map the existing enum string → category row id. Platform
        // categories live on apps_id=0, so the lookup ignores apps_id.
        $categories = DB::connection('intelligence')
            ->table('nervous_system_tool_categories')
            ->where('apps_id', 0)
            ->pluck('id', 'slug')
            ->all();

        foreach ($categories as $slug => $id) {
            DB::connection('intelligence')
                ->table('nervous_system_tools')
                ->where('category', $slug)
                ->update(['tool_category_id' => $id]);
        }

        if (Schema::connection('intelligence')->hasColumn('nervous_system_tools', 'category')) {
            Schema::table('nervous_system_tools', function (Blueprint $table) {
                $table->dropIndex('idx_tools_category');
                $table->dropColumn('category');
            });
        }
    }

    public function down(): void
    {
        Schema::table('nervous_system_tools', function (Blueprint $table) {
            $table->enum('category', [
                'communication', 'calendar', 'knowledge', 'crm', 'commerce',
                'data', 'action', 'code', 'other',
            ])->default('other')->after('tool_type');
            $table->index('category', 'idx_tools_category');
        });

        // Reverse-map id → slug. Categories not represented in the original
        // enum (e.g. 'inventory', 'commerce', 'social', 'workflow', 'ai',
        // 'action-engine', 'ecosystem') fall back to 'other'.
        $reverse = DB::connection('intelligence')
            ->table('nervous_system_tool_categories')
            ->where('apps_id', 0)
            ->pluck('slug', 'id')
            ->all();
        $allowedSlugs = ['communication', 'calendar', 'knowledge', 'crm', 'commerce', 'data', 'action', 'code', 'other'];

        $rows = DB::connection('intelligence')
            ->table('nervous_system_tools')
            ->whereNotNull('tool_category_id')
            ->get(['id', 'tool_category_id']);
        foreach ($rows as $row) {
            $slug = $reverse[$row->tool_category_id] ?? 'other';
            if (! in_array($slug, $allowedSlugs, true)) {
                $slug = 'other';
            }
            DB::connection('intelligence')
                ->table('nervous_system_tools')
                ->where('id', $row->id)
                ->update(['category' => $slug]);
        }

        Schema::table('nervous_system_tools', function (Blueprint $table) {
            $table->dropIndex('idx_tools_category_id');
            $table->dropColumn('tool_category_id');
        });
    }
};
