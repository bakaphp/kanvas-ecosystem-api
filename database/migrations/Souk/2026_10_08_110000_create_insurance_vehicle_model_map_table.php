<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'commerce';

    public function up(): void
    {
        Schema::connection('commerce')->create('insurance_vehicle_model_map', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('apps_id');
            $table->unsignedBigInteger('insurers_id');
            $table->string('brand_key', 100);
            $table->string('model_key', 100);
            $table->unsignedSmallInteger('year')->default(0);
            $table->string('external_model_id', 64);
            $table->string('source', 16)->default('manual');
            $table->boolean('active')->default(1);
            $table->boolean('is_deleted')->default(0);
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->nullable();

            $table->unique(['insurers_id', 'brand_key', 'model_key', 'year'], 'insurance_vehicle_model_map_ref_unique');
            $table->index(['apps_id', 'brand_key', 'model_key', 'active'], 'insurance_vehicle_model_map_lookup_idx');
            $table->foreign('insurers_id', 'insurance_vehicle_model_map_insurer_fk')->references('id')->on('insurers');
        });
    }

    public function down(): void
    {
        Schema::connection('commerce')->dropIfExists('insurance_vehicle_model_map');
    }
};
