<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A payment receipt is the document a customer gets for money received: one per payment, numbered from its own
 * `payment_receipt` sequence, listing every invoice the payment covered.
 */
return new class () extends Migration {
    private const string SEQUENCE_TYPE = 'payment_receipt';

    public function up(): void
    {
        $this->setSequenceTypes([...$this->sequenceTypes(), self::SEQUENCE_TYPE]);

        Schema::connection('accounting')->create('payment_receipts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id');
            $table->uuid('uuid');

            $table->unsignedBigInteger('payment_id');
            $table->string('receipt_number', 64);

            $table->unsignedBigInteger('customer_organization_id')->nullable();
            $table->string('customer_name', 191);

            $table->string('currency', 3);
            $table->decimal('amount', 18, 4);
            $table->json('invoices');

            $table->string('payer_bank', 191)->nullable();
            $table->string('instrument_number', 64)->nullable();
            $table->date('instrument_date')->nullable();
            $table->string('received_by', 191)->nullable();

            $table->dateTime('issued_at');
            $table->unsignedInteger('issued_by_users_id')->nullable();

            $table->boolean('is_deleted')->default(false);
            $table->timestamps();

            $table->unique(['payment_id'], 'pr_payment_uq');
            $table->unique(['apps_id', 'companies_id', 'receipt_number'], 'pr_app_company_number_uq');
            $table->index(['uuid'], 'pr_uuid_idx');
            // Newest first, for the company and for one customer: the two lists a receipts screen reads.
            $table->index(['apps_id', 'companies_id', 'issued_at'], 'pr_app_company_issued_idx');
            $table->index(
                ['apps_id', 'companies_id', 'customer_organization_id', 'issued_at'],
                'pr_app_company_customer_issued_idx'
            );
            $table->index(['apps_id', 'companies_id', 'instrument_number'], 'pr_app_company_instrument_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->dropIfExists('payment_receipts');

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
