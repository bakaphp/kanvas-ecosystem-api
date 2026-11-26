<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A company can now issue its own purchase orders, not only mirror an ERP's. An order of its own has a
 * `document_status` and numbers itself from the `purchase_order` sequence; a mirrored one keeps `document_status`
 * empty and stays read-only.
 */
return new class () extends Migration {
    private const string SEQUENCE_TYPE = 'purchase_order';

    public function up(): void
    {
        Schema::connection('accounting')->table('purchase_orders', function (Blueprint $table): void {
            $table->string('document_status', 24)->nullable()->after('status');
            $table->date('expected_date')->nullable()->after('order_date');
            $table->text('notes')->nullable()->after('currency');
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();

            $table->index(['apps_id', 'companies_id', 'document_status'], 'po_app_company_docstatus_idx');
        });

        $this->setSequenceTypes([...$this->sequenceTypes(), self::SEQUENCE_TYPE]);
    }

    public function down(): void
    {
        DB::connection('accounting')->table('document_sequences')->where('document_type', self::SEQUENCE_TYPE)->delete();

        $this->setSequenceTypes(array_values(array_diff($this->sequenceTypes(), [self::SEQUENCE_TYPE])));

        Schema::connection('accounting')->table('purchase_orders', function (Blueprint $table): void {
            $table->dropIndex('po_app_company_docstatus_idx');
            $table->dropColumn(['document_status', 'expected_date', 'notes', 'cancelled_at', 'cancel_reason']);
        });
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
