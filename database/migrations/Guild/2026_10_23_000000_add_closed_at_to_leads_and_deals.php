<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('crm')->table('leads', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable()->default(null)->after('updated_at');
            $table->index(['apps_id', 'companies_id', 'closed_at'], 'ix_leads_closed_at');
        });

        Schema::connection('crm')->table('deals', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable()->default(null)->after('updated_at');
            $table->index(['apps_id', 'companies_id', 'closed_at'], 'ix_deals_closed_at');
        });
    }

    public function down(): void
    {
        Schema::connection('crm')->table('leads', function (Blueprint $table) {
            $table->dropIndex('ix_leads_closed_at');
            $table->dropColumn('closed_at');
        });

        Schema::connection('crm')->table('deals', function (Blueprint $table) {
            $table->dropIndex('ix_deals_closed_at');
            $table->dropColumn('closed_at');
        });
    }
};
