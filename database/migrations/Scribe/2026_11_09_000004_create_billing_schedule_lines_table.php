<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Typed billing-schedule lines: each one becomes an invoice line every period, so a malformed quantity or
 * price has to be refused at the door, not defaulted later.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->create('billing_schedule_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id');
            $table->unsignedBigInteger('billing_schedule_id');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('description', 500);
            $table->decimal('quantity', 18, 4)->default(1);
            $table->decimal('unit_price', 18, 4);
            $table->decimal('tax_rate', 9, 6)->nullable();
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->unsignedBigInteger('item_id')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();

            $table->foreign('billing_schedule_id', 'billing_schedule_lines_schedule_fk')
                ->references('id')
                ->on('billing_schedules');
            $table->index(['billing_schedule_id', 'is_deleted', 'sort_order'], 'billing_schedule_lines_schedule_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->dropIfExists('billing_schedule_lines');
    }
};
