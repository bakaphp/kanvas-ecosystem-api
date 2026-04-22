<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('crm')->table('organizations', function (Blueprint $table) {
            $table->unsignedInteger('total_employees')->default(0)->index()->after('address');
        });

        DB::connection('crm')->statement('
            UPDATE organizations o
            SET total_employees = (
                SELECT COUNT(*)
                FROM organizations_peoples op
                WHERE op.organizations_id = o.id
            )
        ');
    }

    public function down(): void
    {
        Schema::connection('crm')->table('organizations', function (Blueprint $table) {
            $table->dropIndex(['total_employees']);
            $table->dropColumn('total_employees');
        });
    }
};
