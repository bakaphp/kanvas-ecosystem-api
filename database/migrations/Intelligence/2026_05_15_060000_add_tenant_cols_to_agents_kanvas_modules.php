<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'intelligence';

    /**
     * AppsIdTrait on Intelligence BaseModel auto-injects apps_id on create,
     * so every table on this connection needs the column.
     */
    public function up(): void
    {
        Schema::table('agents_kanvas_modules', function (Blueprint $table) {
            $table->unsignedBigInteger('apps_id')->default(0)->after('id');
            $table->unsignedBigInteger('companies_id')->default(0)->after('apps_id');
            $table->index(['apps_id', 'companies_id'], 'idx_agents_kanvas_modules_tenant');
        });

        DB::connection('intelligence')->statement(<<<'SQL'
            UPDATE agents_kanvas_modules akm
            JOIN agents a ON a.id = akm.agent_id
            SET akm.apps_id = a.apps_id, akm.companies_id = a.companies_id
            WHERE akm.apps_id = 0 OR akm.companies_id = 0
        SQL);
    }

    public function down(): void
    {
        Schema::table('agents_kanvas_modules', function (Blueprint $table) {
            $table->dropIndex('idx_agents_kanvas_modules_tenant');
            $table->dropColumn(['apps_id', 'companies_id']);
        });
    }
};
