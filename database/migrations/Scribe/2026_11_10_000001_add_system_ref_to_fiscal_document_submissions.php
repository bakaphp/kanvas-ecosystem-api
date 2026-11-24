<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The reference a send goes out under. WebPOS copies it into DGII's NumeroFacturaInterna (20 alphanumeric
 * characters) and prints it on the e-CF, so the 36-character attempt uuid earned observation 1323 on every
 * document. Nullable: attempts sent before this column keep answering to their uuid.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->table('fiscal_document_submissions', function (Blueprint $table): void {
            $table->string('system_ref', 20)->nullable()->after('uuid')->unique('fds_system_ref_uq');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->table('fiscal_document_submissions', function (Blueprint $table): void {
            $table->dropUnique('fds_system_ref_uq');
            $table->dropColumn('system_ref');
        });
    }
};
