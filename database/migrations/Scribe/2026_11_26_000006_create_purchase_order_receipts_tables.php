<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A goods receipt records what arrived against a purchase order the company issued, numbered from its own
 * `goods_receipt` sequence. The order's lines keep the running received and open quantities.
 */
return new class () extends Migration {
    private const string SEQUENCE_TYPE = 'goods_receipt';

    public function up(): void
    {
        Schema::connection('accounting')->create('purchase_order_receipts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id');
            $table->uuid('uuid');

            $table->unsignedBigInteger('purchase_order_id');
            $table->string('receipt_number', 40);
            $table->date('received_on');
            $table->string('reference', 191)->nullable();
            $table->text('notes')->nullable();

            $table->unsignedInteger('users_id')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();

            $table->unique(['apps_id', 'companies_id', 'receipt_number'], 'por_app_company_number_uq');
            $table->index('purchase_order_id', 'por_order_idx');
            $table->index(['uuid'], 'por_uuid_idx');
            $table->index(['apps_id', 'companies_id', 'received_on'], 'por_app_company_received_idx');
            $table->index(['apps_id', 'companies_id', 'reference'], 'por_app_company_reference_idx');
        });

        Schema::connection('accounting')->create('purchase_order_receipt_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('purchase_order_receipt_id');
            $table->unsignedBigInteger('purchase_order_line_id');
            $table->decimal('quantity', 18, 6);

            $table->index('purchase_order_receipt_id', 'porl_receipt_idx');
            $table->index('purchase_order_line_id', 'porl_line_idx');
        });

        // The match reads the other bills linked to an order; the column was reserved without an index.
        Schema::connection('accounting')->table('bills', function (Blueprint $table): void {
            $table->index('purchase_order_id', 'bills_purchase_order_idx');
        });

        $this->setSequenceTypes([...$this->sequenceTypes(), self::SEQUENCE_TYPE]);
    }

    public function down(): void
    {
        Schema::connection('accounting')->dropIfExists('purchase_order_receipt_lines');
        Schema::connection('accounting')->dropIfExists('purchase_order_receipts');

        Schema::connection('accounting')->table('bills', function (Blueprint $table): void {
            $table->dropIndex('bills_purchase_order_idx');
        });

        DB::connection('accounting')
            ->table('document_sequences')
            ->where('document_type', self::SEQUENCE_TYPE)
            ->delete();

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
