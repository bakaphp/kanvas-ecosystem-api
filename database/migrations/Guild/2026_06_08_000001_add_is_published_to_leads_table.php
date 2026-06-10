<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('crm')->table('leads', function (Blueprint $table) {
            $table->tinyInteger('is_published')->default(1)->index()->after('is_deleted');
        });
    }

    public function down(): void
    {
        Schema::connection('crm')->table('leads', function (Blueprint $table) {
            $table->dropColumn('is_published');
        });
    }
};
