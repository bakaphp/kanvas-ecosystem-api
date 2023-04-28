<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('leads_receivers', function (Blueprint $table) {
            $table->integer('leads_sources_id')->default(0)->after('rotations_id');
            $table->integer('lead_types_id')->default(0)->after('leads_sources_id');
            $table->index('leads_sources_id');
            $table->index('lead_types_id');
            $table->index('companies_branches_id');
            $table->index('total_leads');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
    }
};
