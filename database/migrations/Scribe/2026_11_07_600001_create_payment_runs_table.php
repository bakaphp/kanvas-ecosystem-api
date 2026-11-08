<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A payment run: the bills the AP agent proposes to pay, grouped by the bank account they would leave from,
 * sent to the person who pays. The agent never moves money; the run only follows the payments that person
 * reports (RecordBillPaymentAction), so its status is derived from its lines and a line from its bill.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->create('payment_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id');
            $table->unsignedInteger('users_id')->nullable();
            $table->unsignedBigInteger('agent_id')->nullable();

            $table->string('status', 16);
            $table->date('due_by')->nullable();
            $table->unsignedInteger('line_count')->default(0);
            $table->json('groups')->nullable();

            $table->unsignedInteger('sent_to_users_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();

            $table->json('metadata')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();

            $table->index(['apps_id', 'companies_id', 'status'], 'pr_company_status_idx');
            $table->index(['uuid'], 'pr_uuid_idx');
        });

        Schema::connection('accounting')->create('payment_run_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payment_run_id');
            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id');
            $table->unsignedBigInteger('bill_id');
            $table->unsignedBigInteger('bank_account_id')->nullable();

            $table->string('vendor_name')->nullable();
            $table->string('bill_number')->nullable();
            $table->date('due_date')->nullable();
            $table->string('currency', 3);
            $table->decimal('amount_native', 18, 4);

            $table->string('status', 16);
            $table->timestamp('paid_at')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();

            $table->unique(['payment_run_id', 'bill_id'], 'prl_run_bill_unique');
            $table->index(['apps_id', 'companies_id', 'bill_id', 'status'], 'prl_bill_status_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->dropIfExists('payment_run_lines');
        Schema::connection('accounting')->dropIfExists('payment_runs');
    }
};
