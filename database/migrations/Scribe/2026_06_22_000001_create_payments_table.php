<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->create('payments', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedInteger('apps_id')->index();
            $table->unsignedBigInteger('companies_id')->index();
            $table->unsignedBigInteger('users_id')->nullable();

            $table->decimal('amount_native', 18, 4);
            $table->decimal('amount_base', 18, 4);
            $table->string('currency', 8);
            $table->decimal('fx_rate_to_base', 20, 10);
            $table->dateTime('fx_rate_at')->nullable();

            $table->date('payment_date');
            $table->dateTime('cleared_at')->nullable();
            $table->dateTime('reconciled_at')->nullable();

            $table->enum('direction', ['inbound', 'outbound']);
            $table->string('method', 32);
            $table->string('status', 32)->default('cleared');
            $table->unsignedBigInteger('bank_account_id')->nullable()->index();
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();

            $table->enum('source', ['kanvas', 'adm_cloud', 'mercury', 'stripe_billing', 'manual'])->default('kanvas');
            $table->string('external_id', 191)->nullable();
            $table->string('external_url')->nullable();
            $table->dateTime('reversed_at')->nullable();
            $table->unsignedBigInteger('reversed_by_users_id')->nullable();
            $table->string('reversal_reason')->nullable();
            $table->json('metadata')->nullable();

            $table->boolean('is_deleted')->default(false)->index();
            $table->timestamps();

            $table->index(['apps_id', 'companies_id', 'payment_date'], 'payments_app_company_date_idx');
            $table->index(['apps_id', 'companies_id', 'direction', 'status'], 'payments_app_company_dir_status_idx');
            $table->unique(['apps_id', 'source', 'external_id'], 'payments_app_source_external_id_uniq');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->dropIfExists('payments');
    }
};
