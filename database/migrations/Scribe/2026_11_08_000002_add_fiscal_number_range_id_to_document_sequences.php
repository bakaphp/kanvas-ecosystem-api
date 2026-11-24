<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->table('document_sequences', function (Blueprint $table) {
            $table->unsignedBigInteger('fiscal_number_range_id')->nullable()->after('padding');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->table('document_sequences', function (Blueprint $table) {
            $table->dropColumn('fiscal_number_range_id');
        });
    }
};
