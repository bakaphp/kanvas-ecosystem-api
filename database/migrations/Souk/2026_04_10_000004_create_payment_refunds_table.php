<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::connection('commerce')->hasTable('payment_refunds')) {
            Schema::connection('commerce')->create('payment_refunds', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('apps_id')->index();
                $table->unsignedBigInteger('companies_id')->index();
                $table->unsignedBigInteger('payments_id')->index();
                $table->unsignedBigInteger('users_id')->index();
                $table->char('uuid', 36)->index();
                $table->decimal('amount', 10, 2);
                $table->string('currency', 10)->nullable();
                $table->string('reason', 255)->nullable();
                $table->string('processor_refund_id', 255)->nullable()->index();
                $table->string('status', 50)->default('pending')->index();
                $table->json('metadata')->nullable();
                $table->boolean('is_deleted')->default(false)->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::connection('commerce')->dropIfExists('payment_refunds');
    }
};
