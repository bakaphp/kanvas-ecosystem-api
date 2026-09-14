<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who *used to* approve a vendor's bills matters when reviewing an old approval, so removing an
 * approver soft-deletes the row rather than dropping it. The composite index matches the only query
 * shape this table has — every approver lookup is "this organization's live approvers".
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('crm')->table('organization_approvers', function (Blueprint $table) {
            $table->boolean('is_deleted')->default(false);
            $table->dateTime('updated_at')->nullable();

            $table->index(['organizations_id', 'is_deleted'], 'organizations_id_is_deleted');
        });
    }

    public function down(): void
    {
        Schema::connection('crm')->table('organization_approvers', function (Blueprint $table) {
            $table->dropIndex('organizations_id_is_deleted');
            $table->dropColumn(['is_deleted', 'updated_at']);
        });
    }
};
