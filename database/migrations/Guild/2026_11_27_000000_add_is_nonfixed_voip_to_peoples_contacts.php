<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * No after(): appended at the end so MySQL adds it INSTANT on a large table.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('crm')->table('peoples_contacts', function (Blueprint $table) {
            $table->boolean('is_nonfixed_voip')->default(false);
        });
    }

    public function down(): void
    {
        Schema::connection('crm')->table('peoples_contacts', function (Blueprint $table) {
            $table->dropColumn('is_nonfixed_voip');
        });
    }
};
