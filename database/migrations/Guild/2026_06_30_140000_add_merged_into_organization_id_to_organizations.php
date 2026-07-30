<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('crm')->table('organizations', function (Blueprint $table) {
            // Audit pointer: when a duplicate org is merged away (soft-deleted), this records
            // which surviving org it became, so a merge is traceable and reversible.
            $table->unsignedBigInteger('merged_into_organization_id')->nullable();
            $table->index('merged_into_organization_id', 'merged_into_org_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('crm')->table('organizations', function (Blueprint $table) {
            $table->dropIndex('merged_into_org_idx');
            $table->dropColumn('merged_into_organization_id');
        });
    }
};
