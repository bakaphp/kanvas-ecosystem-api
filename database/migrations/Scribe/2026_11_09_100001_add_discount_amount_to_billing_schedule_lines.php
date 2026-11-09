<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A SOW payment bills its share of every quote line, discount included, so the invoice shows the quote's lines as
 * quoted instead of a price with the discount folded in.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->table('billing_schedule_lines', function (Blueprint $table): void {
            $table->decimal('discount_amount', 18, 4)->default(0)->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->table('billing_schedule_lines', function (Blueprint $table): void {
            $table->dropColumn('discount_amount');
        });
    }
};
