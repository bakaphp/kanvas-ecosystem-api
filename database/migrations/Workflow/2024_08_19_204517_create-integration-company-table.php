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
        Schema::create('integration_companies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('companies_id');
            $table->unsignedBigInteger('integrations_id');
            $table->unsignedBigInteger('status_id');
            $table->unsignedBigInteger('region_id');
            $table->text('config')->nullable();
            $table->timestamps();
            $table->boolean('is_deleted')->default(false);

            // Foreign key constraints
            $table->foreign('integrations_id')->references('id')->on('integrations');
            $table->foreign('status_id')->references('id')->on('status');
            $table->index(['companies_id', 'integrations_id', 'region_id'], 'integrations_companies_regions_index');
            $table->index('companies_id', 'companies_id_index');
            $table->index('status_id', 'status_id_index');
            $table->index('region_id', 'region_id_index');

            // Check how can we get the ecosystem database name to make a reference
            //$table->foreign('companies_id')->references('id')->on('ecosystem_db.companies');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('integration_companies');
    }
};
