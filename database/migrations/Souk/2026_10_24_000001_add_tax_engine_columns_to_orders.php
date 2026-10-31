<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('commerce')->table('orders', function (Blueprint $table) {
            if (! Schema::connection('commerce')->hasColumn('orders', 'totals_version')) {
                $table->unsignedTinyInteger('totals_version')->default(1)->after('tax_amount');
            }

            if (! Schema::connection('commerce')->hasColumn('orders', 'subtotal_amount')) {
                $table->decimal('subtotal_amount', 15, 4)->nullable()->after('totals_version');
            }

            if (! Schema::connection('commerce')->hasColumn('orders', 'total_payable_amount')) {
                $table->decimal('total_payable_amount', 15, 4)->nullable()->after('subtotal_amount');
            }

            if (! Schema::connection('commerce')->hasColumn('orders', 'tax_source')) {
                $table->string('tax_source', 16)->default('none')->after('total_payable_amount');
            }

            if (! Schema::connection('commerce')->hasColumn('orders', 'tax_status')) {
                $table->string('tax_status', 20)->nullable()->after('tax_source');
            }

            if (! Schema::connection('commerce')->hasColumn('orders', 'fulfillment_type')) {
                $table->string('fulfillment_type', 16)->default('ship')->after('tax_status');
            }

            if (! Schema::connection('commerce')->hasColumn('orders', 'payment_intent_id')) {
                $table->string('payment_intent_id', 191)->nullable()->unique()->after('fulfillment_type');
            }
        });

        Schema::connection('commerce')->table('order_items', function (Blueprint $table) {
            if (! Schema::connection('commerce')->hasColumn('order_items', 'tax_amount')) {
                $table->decimal('tax_amount', 15, 4)->default(0)->after('tax_rate');
            }

            if (! Schema::connection('commerce')->hasColumn('order_items', 'tax_category')) {
                $table->string('tax_category', 32)->nullable()->after('tax_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('commerce')->table('orders', function (Blueprint $table) {
            $columns = array_filter(
                ['totals_version', 'subtotal_amount', 'total_payable_amount', 'tax_source', 'tax_status', 'fulfillment_type', 'payment_intent_id'],
                fn ($col) => Schema::connection('commerce')->hasColumn('orders', $col)
            );

            if (! empty($columns)) {
                $table->dropColumn(array_values($columns));
            }
        });

        Schema::connection('commerce')->table('order_items', function (Blueprint $table) {
            $columns = array_filter(
                ['tax_amount', 'tax_category'],
                fn ($col) => Schema::connection('commerce')->hasColumn('order_items', $col)
            );

            if (! empty($columns)) {
                $table->dropColumn(array_values($columns));
            }
        });
    }
};
