<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A sales order is the company's record of what a customer committed to buy: their purchase order, or our proforma
 * signed and stamped by them. It is reviewed against the quote, confirmed by Commercial and accepted by Operations
 * before anything ships. It numbers itself from the `sales_order` sequence.
 */
return new class () extends Migration {
    private const string SEQUENCE_TYPE = 'sales_order';

    public function up(): void
    {
        Schema::connection('accounting')->create('sales_orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id');
            $table->uuid('uuid');

            $table->string('order_number', 40);
            $table->string('document_status', 24)->default('received');
            $table->string('po_source', 24);

            $table->unsignedBigInteger('customer_organization_id');
            $table->unsignedBigInteger('quote_id')->nullable();
            $table->string('customer_po_number', 64)->nullable();
            $table->date('customer_po_date')->nullable();
            $table->json('signature_checklist')->nullable();

            $table->string('currency', 3);
            $table->decimal('order_total', 18, 4)->default(0);
            $table->string('incoterm', 16)->nullable();
            $table->string('consignee', 191)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->date('requested_date')->nullable();
            $table->text('notes')->nullable();

            $table->json('review')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->dateTime('feasibility_confirmed_at')->nullable();

            $table->date('etd')->nullable();
            $table->date('eta')->nullable();

            $table->dateTime('received_at');
            $table->dateTime('confirmed_at')->nullable();
            $table->dateTime('accepted_at')->nullable();
            $table->dateTime('returned_at')->nullable();
            $table->string('return_reason', 255)->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();

            $table->unsignedInteger('users_id')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();

            $table->unique(['apps_id', 'companies_id', 'order_number'], 'so_app_company_number_uq');
            $table->unique(['apps_id', 'companies_id', 'customer_organization_id', 'customer_po_number'], 'so_app_company_customer_po_uq');
            $table->index(['apps_id', 'companies_id', 'document_status'], 'so_app_company_status_idx');
        });

        Schema::connection('accounting')->create('sales_order_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('sales_order_id');
            $table->unsignedInteger('line_number');
            $table->string('sku', 191)->nullable();
            $table->string('description', 500);
            $table->decimal('quantity', 18, 6);
            $table->decimal('unit_price', 18, 6);
            $table->decimal('line_total', 18, 4);

            $table->index('sales_order_id', 'sol_order_idx');
        });

        $this->setSequenceTypes([...$this->sequenceTypes(), self::SEQUENCE_TYPE]);
    }

    public function down(): void
    {
        Schema::connection('accounting')->dropIfExists('sales_order_lines');
        Schema::connection('accounting')->dropIfExists('sales_orders');

        DB::connection('accounting')->table('document_sequences')->where('document_type', self::SEQUENCE_TYPE)->delete();

        $this->setSequenceTypes(array_values(array_diff($this->sequenceTypes(), [self::SEQUENCE_TYPE])));
    }

    /**
     * The enum's current values, read from the column so this migration keeps whatever other migrations added.
     *
     * @return list<string>
     */
    private function sequenceTypes(): array
    {
        $column = DB::connection('accounting')->selectOne("SHOW COLUMNS FROM document_sequences LIKE 'document_type'");

        preg_match_all("/'([^']+)'/", (string) $column->Type, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * @param list<string> $types
     */
    private function setSequenceTypes(array $types): void
    {
        $list = implode(', ', array_map(static fn (string $type): string => "'{$type}'", $types));

        DB::connection('accounting')->statement("ALTER TABLE document_sequences MODIFY document_type ENUM({$list}) NOT NULL");
    }
};
