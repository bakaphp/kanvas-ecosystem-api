<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An order the company issues is approved before it is sent, and what was sent to whom is kept.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->table('purchase_orders', function (Blueprint $table): void {
            $table->dateTime('approved_at')->nullable()->after('expected_date');
            $table->dateTime('sent_at')->nullable()->after('approved_at');
            $table->string('sent_to', 500)->nullable()->after('sent_at');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->table('purchase_orders', function (Blueprint $table): void {
            $table->dropColumn(['approved_at', 'sent_at', 'sent_to']);
        });
    }
};
