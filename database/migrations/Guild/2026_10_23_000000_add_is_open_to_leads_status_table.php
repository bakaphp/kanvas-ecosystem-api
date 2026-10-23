<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('crm')->table('leads_status', function (Blueprint $table) {
            $table->boolean('is_open')->default(false)->after('is_default');
        });

        // Ids 1 and 2 were the hardcoded open set; keep them open so no lead changes state.
        DB::connection('crm')->table('leads_status')->whereIn('id', [1, 2])->update(['is_open' => true]);
    }

    public function down(): void
    {
        Schema::connection('crm')->table('leads_status', function (Blueprint $table) {
            $table->dropColumn('is_open');
        });
    }
};
