<?php

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (method_exists(Builder::class, 'dropUniqueIfExists')) {
            Schema::table('order_types', function (Blueprint $table) {
                $table->dropUniqueIfExists(['apps_id', 'name']);
            });
        } else {
            try {
                Schema::table('order_types', function (Blueprint $table) {
                    $table->dropUnique(['apps_id', 'name']);
                });
            } catch (\Throwable $e) {
            }
        }

        Schema::table('order_types', function (Blueprint $table) {
            $table->unique(['name', 'companies_id', 'apps_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
    }
};
