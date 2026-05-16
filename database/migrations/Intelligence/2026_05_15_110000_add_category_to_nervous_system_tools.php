<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'intelligence';

    public function up(): void
    {
        Schema::table('nervous_system_tools', function (Blueprint $table) {
            // Functional grouping for the dashboard "Tools" tab. Distinct from
            // tool_type (which is about ownership: system / custom / webhook).
            $table->enum('category', [
                'communication',
                'calendar',
                'knowledge',
                'crm',
                'commerce',
                'data',
                'action',
                'code',
                'other',
            ])->default('other')->after('tool_type');
            $table->index('category', 'idx_tools_category');
        });
    }

    public function down(): void
    {
        Schema::table('nervous_system_tools', function (Blueprint $table) {
            $table->dropIndex('idx_tools_category');
            $table->dropColumn('category');
        });
    }
};
