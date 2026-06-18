<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // Idempotent — earlier partial runs may have already added the column without recording the migration.
        if (! Schema::connection('accounting')->hasColumn('accounts', 'path')) {
            Schema::connection('accounting')->table('accounts', function (Blueprint $table): void {
                $table->string('path')->nullable()->after('parent_account_id');
                $table->index(['apps_id', 'companies_id', 'path'], 'accounts_app_company_path_idx');
            });
        }

        // Roots (no parent) → path = id
        DB::connection('accounting')->update(<<<'SQL'
            UPDATE accounts
            SET path = id
            WHERE path IS NULL AND parent_account_id IS NULL
        SQL);

        // Walk children: MySQL doesn't support LIMIT on UPDATE+JOIN, so run the full join until no new rows update.
        do {
            $updated = DB::connection('accounting')->update(<<<'SQL'
                UPDATE accounts AS a
                JOIN accounts AS p ON p.id = a.parent_account_id AND p.path IS NOT NULL
                SET a.path = CONCAT(p.path, '.', a.id)
                WHERE a.path IS NULL
            SQL);
        } while ($updated > 0);
    }

    public function down(): void
    {
        Schema::connection('accounting')->table('accounts', function (Blueprint $table): void {
            $table->dropIndex('accounts_app_company_path_idx');
            $table->dropColumn('path');
        });
    }
};
