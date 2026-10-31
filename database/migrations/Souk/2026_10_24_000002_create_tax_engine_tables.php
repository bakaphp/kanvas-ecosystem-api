<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::connection('commerce')->hasTable('tax_registrations')) {
            Schema::connection('commerce')->create('tax_registrations', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->char('uuid', 36)->unique();
                $table->unsignedBigInteger('apps_id');
                $table->char('jurisdiction_country', 2);
                $table->string('jurisdiction_state', 10)->nullable();
                $table->string('registration_number', 64)->nullable();
                $table->string('provider', 32);
                $table->date('effective_from');
                $table->date('effective_to')->nullable();
                $table->boolean('is_active')->default(true);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->tinyInteger('is_deleted')->default(0);

                $table->index(
                    ['apps_id', 'jurisdiction_country', 'jurisdiction_state', 'effective_from'],
                    'tax_regs_app_jurisdiction_idx'
                );
            });
        }

        if (! Schema::connection('commerce')->hasTable('tax_routing_rules')) {
            Schema::connection('commerce')->create('tax_routing_rules', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->char('uuid', 36)->unique();
                $table->unsignedBigInteger('apps_id');
                $table->char('destination_country', 2);
                $table->string('destination_state', 10)->nullable();
                $table->string('product_scope', 32)->nullable();
                $table->string('provider', 32);
                $table->unsignedTinyInteger('priority')->default(100);
                $table->boolean('tax_shipping')->default(false);
                $table->decimal('rate', 8, 6)->default(0);
                $table->json('category_codes')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->tinyInteger('is_deleted')->default(0);

                $table->index(['apps_id', 'destination_country'], 'tax_routing_app_country_idx');
            });
        }

        if (! Schema::connection('commerce')->hasTable('customer_tax_exemptions')) {
            Schema::connection('commerce')->create('customer_tax_exemptions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->char('uuid', 36)->unique();
                $table->unsignedBigInteger('apps_id');
                $table->unsignedBigInteger('people_id')->nullable();
                $table->unsignedBigInteger('companies_id')->nullable();
                $table->string('exemption_type', 32);
                $table->char('jurisdiction_country', 2);
                $table->string('jurisdiction_state', 10)->nullable();
                $table->string('certificate_number', 64)->nullable();
                $table->date('expires_at')->nullable();
                $table->unsignedBigInteger('evidence_filesystem_id')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->tinyInteger('is_deleted')->default(0);
            });
        }

        if (! Schema::connection('commerce')->hasTable('order_tax_snapshots')) {
            Schema::connection('commerce')->create('order_tax_snapshots', function (Blueprint $table) {
                $table->unsignedInteger('id')->autoIncrement()->primary();
                $table->char('uuid', 36)->unique();
                $table->unsignedBigInteger('apps_id');
                $table->unsignedBigInteger('order_id')->nullable();
                $table->string('cart_session_id', 64)->nullable();
                $table->string('kind', 20)->default('calculation');
                $table->string('treatment', 32)->default('taxed');
                $table->string('status', 32)->default('calculated');
                $table->string('provider', 32)->default('none');
                $table->char('input_hash', 64)->nullable();
                $table->decimal('tax_amount', 15, 4)->default(0);
                $table->decimal('taxable_amount', 15, 4)->default(0);
                $table->decimal('remaining_reversible_amount', 15, 4)->default(0);
                $table->decimal('remaining_reversible_tax', 15, 4)->default(0);
                $table->char('gateway_attempt_key', 64)->nullable()->unique();
                $table->string('gateway_attempt_status', 32)->nullable()->default('pending');
                $table->unsignedTinyInteger('gateway_attempt_no')->default(1);
                $table->string('merchant_reference', 20)->nullable();
                $table->string('payment_intent_id', 191)->nullable();
                $table->string('provider_transaction_id', 191)->nullable();
                $table->char('provider_account_fingerprint', 64)->nullable();
                $table->json('checkout_payload')->nullable();
                $table->boolean('souk_tax_engine_enabled')->default(false);
                $table->json('metadata')->nullable();
                $table->unsignedInteger('parent_snapshot_id')->nullable();
                $table->timestamps();
                $table->tinyInteger('is_deleted')->default(0);

                $table->index(['apps_id', 'order_id'], 'tax_snapshots_app_order_idx');
                $table->index(['apps_id', 'cart_session_id'], 'tax_snapshots_app_cart_idx');
                $table->index('status', 'tax_snapshots_status_idx');
                $table->index('gateway_attempt_status', 'tax_snapshots_gateway_status_idx');
            });
        }

        if (! Schema::connection('commerce')->hasTable('order_tax_components')) {
            Schema::connection('commerce')->create('order_tax_components', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->char('uuid', 36)->unique();
                $table->unsignedInteger('snapshot_id');
                $table->string('line_id', 64);
                $table->string('tax_type', 32);
                $table->string('jurisdiction', 64)->nullable();
                $table->decimal('rate', 8, 6)->default(0);
                $table->decimal('taxable_amount', 15, 4)->default(0);
                $table->decimal('tax_amount', 15, 4)->default(0);
                $table->string('taxability_reason', 64)->nullable();
                $table->string('sourcing', 16)->nullable();
                $table->tinyInteger('is_deleted')->default(0);
                $table->timestamps();

                $table->index('snapshot_id', 'tax_components_snapshot_idx');
            });
        }

        if (! Schema::connection('commerce')->hasTable('tax_operations')) {
            Schema::connection('commerce')->create('tax_operations', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->char('uuid', 36)->unique();
                $table->unsignedInteger('snapshot_id');
                $table->string('kind', 16);
                $table->char('idempotency_key', 64)->unique();
                $table->string('status', 32)->default('pending');
                $table->string('provider', 32);
                $table->json('provider_response')->nullable();
                $table->timestamp('locked_until')->nullable();
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->timestamps();
                $table->tinyInteger('is_deleted')->default(0);

                $table->index(['snapshot_id', 'kind'], 'tax_ops_snapshot_kind_idx');
                $table->index(['status', 'locked_until'], 'tax_ops_status_lock_idx');
            });
        }

        if (! Schema::connection('commerce')->hasTable('credit_reservations')) {
            Schema::connection('commerce')->create('credit_reservations', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->char('uuid', 36)->unique();
                $table->unsignedInteger('snapshot_id');
                $table->unsignedBigInteger('companies_id');
                $table->unsignedBigInteger('discount_id');
                $table->decimal('amount', 15, 4);
                $table->string('status', 16)->default('held');
                $table->timestamps();
                $table->tinyInteger('is_deleted')->default(0);

                $table->index('snapshot_id', 'credit_reservations_snapshot_idx');
                $table->index(['discount_id', 'status'], 'credit_reservations_discount_status_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::connection('commerce')->dropIfExists('credit_reservations');
        Schema::connection('commerce')->dropIfExists('tax_operations');
        Schema::connection('commerce')->dropIfExists('order_tax_components');
        Schema::connection('commerce')->dropIfExists('order_tax_snapshots');
        Schema::connection('commerce')->dropIfExists('customer_tax_exemptions');
        Schema::connection('commerce')->dropIfExists('tax_routing_rules');
        Schema::connection('commerce')->dropIfExists('tax_registrations');
    }
};
