<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    private const array BASE_TYPES = [
        'invoice',
        'credit_note',
        'quote',
        'bill',
        'vendor_credit',
        'sales_receipt',
        'expense',
        'journal_entry',
    ];

    private const array ECF_TYPES = [
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
        Schema::connection('accounting')->table('document_sequences', function (Blueprint $table) {
            $table->unsignedBigInteger('range_start')->nullable()->after('next_value');
            $table->unsignedBigInteger('range_end')->nullable()->after('range_start');
            $table->date('expires_at')->nullable()->after('range_end');
            $table->unsignedTinyInteger('padding')->nullable()->after('expires_at');
        });

        $this->setDocumentTypes([...self::BASE_TYPES, ...self::ECF_TYPES]);
    }

    public function down(): void
    {
        DB::connection('accounting')->table('document_sequences')->whereIn('document_type', self::ECF_TYPES)->delete();

        $this->setDocumentTypes(self::BASE_TYPES);

        Schema::connection('accounting')->table('document_sequences', function (Blueprint $table) {
            $table->dropColumn(['range_start', 'range_end', 'expires_at', 'padding']);
        });
    }

    private function setDocumentTypes(array $types): void
    {
        $values = implode(', ', array_map(fn (string $type): string => "'{$type}'", $types));

        DB::connection('accounting')->statement(
            "ALTER TABLE document_sequences MODIFY document_type ENUM({$values}) NOT NULL"
        );
    }
};
