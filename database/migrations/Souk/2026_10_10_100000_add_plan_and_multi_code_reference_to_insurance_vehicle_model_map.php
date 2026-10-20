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
        Schema::connection('commerce')->table('insurance_vehicle_model_map', function (Blueprint $table) {
            $table->string('plan_code', 32)->default('')->after('insurers_id');
            $table->string('version_key', 100)->default('')->after('model_key');
            $table->string('external_brand_id', 64)->nullable()->after('external_model_id');
            $table->string('external_version_id', 64)->nullable()->after('external_brand_id');
        });

        Schema::connection('commerce')->table('insurance_vehicle_model_map', function (Blueprint $table) {
            $table->unique(
                ['insurers_id', 'plan_code', 'brand_key', 'model_key', 'version_key', 'year'],
                'insurance_vehicle_model_map_reference_unique'
            );
        });

        Schema::connection('commerce')->table('insurance_vehicle_model_map', function (Blueprint $table) {
            $table->dropUnique('insurance_vehicle_model_map_ref_unique');
        });
    }

    public function down(): void
    {
        DB::connection('commerce')
            ->table('insurance_vehicle_model_map')
            ->where(fn ($query) => $query->where('plan_code', '!=', '')->orWhere('version_key', '!=', ''))
            ->delete();

        Schema::connection('commerce')->table('insurance_vehicle_model_map', function (Blueprint $table) {
            $table->unique(['insurers_id', 'brand_key', 'model_key', 'year'], 'insurance_vehicle_model_map_ref_unique');
        });

        Schema::connection('commerce')->table('insurance_vehicle_model_map', function (Blueprint $table) {
            $table->dropUnique('insurance_vehicle_model_map_reference_unique');
            $table->dropColumn(['plan_code', 'version_key', 'external_brand_id', 'external_version_id']);
        });
    }
};
