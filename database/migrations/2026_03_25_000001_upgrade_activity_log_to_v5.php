<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->json('attribute_changes')->nullable()->after('causer_id');
        });

        // Migrate existing change data from properties to attribute_changes
        DB::table('activity_log')->whereNotNull('properties')->eachById(function ($row) {
            $properties = json_decode($row->properties, true);

            if (! is_array($properties)) {
                return;
            }

            $changes = array_intersect_key($properties, array_flip(['attributes', 'old']));
            $remaining = array_diff_key($properties, array_flip(['attributes', 'old']));

            DB::table('activity_log')->where('id', $row->id)->update([
                'attribute_changes' => empty($changes) ? null : json_encode($changes),
                'properties' => empty($remaining) ? null : json_encode($remaining),
            ]);
        });

        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropColumn('batch_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->uuid('batch_uuid')->nullable()->after('properties');
        });

        // Move attribute_changes data back to properties
        DB::table('activity_log')->whereNotNull('attribute_changes')->eachById(function ($row) {
            $attributeChanges = json_decode($row->attribute_changes, true);
            $properties = json_decode($row->properties, true) ?? [];

            if (is_array($attributeChanges)) {
                $merged = array_merge($properties, $attributeChanges);
                DB::table('activity_log')->where('id', $row->id)->update([
                    'properties' => json_encode($merged),
                ]);
            }
        });

        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropColumn('attribute_changes');
        });
    }
};
