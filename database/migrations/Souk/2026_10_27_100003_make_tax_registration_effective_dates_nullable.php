<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('commerce')->table('tax_registrations', function (Blueprint $table) {
            $table->date('effective_from')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::connection('commerce')->table('tax_registrations', function (Blueprint $table) {
            $table->date('effective_from')->nullable(false)->change();
        });
    }
};
