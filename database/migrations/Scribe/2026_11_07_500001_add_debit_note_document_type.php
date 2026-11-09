<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A debit note (DGII E33) raises what the customer owes on an issued invoice. It lives in `invoices` beside
 * credit notes, and a company without e-CF numbers it from its own `debit_note` sequence.
 */
return new class () extends Migration {
    private const array INVOICE_TYPES = ['invoice', 'credit_note'];

    private const array SEQUENCE_TYPES = [
        'invoice',
        'credit_note',
        'quote',
        'bill',
        'vendor_credit',
        'sales_receipt',
        'expense',
        'journal_entry',
        'ecf_31',
        'ecf_32',
        'ecf_33',
        'ecf_34',
        'ecf_41',
        'ecf_43',
        'ecf_44',
        'ecf_45',
        'ecf_46',
        'ecf_47',
    ];

    public function up(): void
    {
        $this->setEnum(
            'invoices',
            'document_type',
            [...self::INVOICE_TYPES, 'debit_note'],
            "DEFAULT 'invoice'"
        );
        $this->setEnum('document_sequences', 'document_type', [...self::SEQUENCE_TYPES, 'debit_note']);
    }

    public function down(): void
    {
        DB::connection('accounting')->table('document_sequences')->where('document_type', 'debit_note')->delete();

        $this->setEnum(
            'invoices',
            'document_type',
            self::INVOICE_TYPES,
            "DEFAULT 'invoice'"
        );
        $this->setEnum('document_sequences', 'document_type', self::SEQUENCE_TYPES);
    }

    private function setEnum(
        string $table,
        string $column,
        array $values,
        string $suffix = ''
    ): void {
        $list = implode(', ', array_map(fn (string $value): string => "'{$value}'", $values));

        DB::connection('accounting')->statement(
            trim("ALTER TABLE {$table} MODIFY {$column} ENUM({$list}) NOT NULL {$suffix}")
        );
    }
};
