<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('commerce')->table('shipping_rate_cards', function (Blueprint $table) {
            $table->string('weight_unit', 8)->nullable()->after('currency');
        });

        DB::connection('commerce')->table('shipping_rate_cards')->update(['weight_unit' => 'kg']);

        Schema::connection('commerce')->table('shipping_rate_cards', function (Blueprint $table) {
            $table->string('weight_unit', 8)->nullable(false)->change();
        });

        Schema::connection('commerce')->table('shipping_rate_card_rates', function (Blueprint $table) {
            $table->decimal('max_weight', 10, 3)->nullable()->after('zone');
        });

        DB::connection('commerce')->table('shipping_rate_card_rates')->update([
            'max_weight' => DB::raw('max_grams / 1000'),
        ]);

        Schema::connection('commerce')->table('shipping_rate_card_rates', function (Blueprint $table) {
            $table->decimal('max_weight', 10, 3)->nullable(false)->change();
            $table->unique(['rate_card_id', 'zone', 'max_weight'], 'shipping_rate_card_rates_weight_unique');
        });

        Schema::connection('commerce')->table('shipping_rate_card_rates', function (Blueprint $table) {
            $table->dropUnique('shipping_rate_card_rates_unique');
            $table->dropColumn('max_grams');
        });
    }

    public function down(): void
    {
        Schema::connection('commerce')->table('shipping_rate_card_rates', function (Blueprint $table) {
            $table->unsignedInteger('max_grams')->nullable()->after('zone');
        });

        DB::connection('commerce')->table('shipping_rate_card_rates')->update([
            'max_grams' => DB::raw('ROUND(max_weight * 1000)'),
        ]);

        Schema::connection('commerce')->table('shipping_rate_card_rates', function (Blueprint $table) {
            $table->unsignedInteger('max_grams')->nullable(false)->change();
            $table->unique(['rate_card_id', 'zone', 'max_grams'], 'shipping_rate_card_rates_unique');
        });

        Schema::connection('commerce')->table('shipping_rate_card_rates', function (Blueprint $table) {
            $table->dropUnique('shipping_rate_card_rates_weight_unique');
            $table->dropColumn('max_weight');
        });

        Schema::connection('commerce')->table('shipping_rate_cards', function (Blueprint $table) {
            $table->dropColumn('weight_unit');
        });
    }
};
