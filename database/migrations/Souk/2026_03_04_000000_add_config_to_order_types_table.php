<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('commerce')->table('order_types', function (Blueprint $table) {
            $table->json('config')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::connection('commerce')->table('order_types', function (Blueprint $table) {
            $table->dropColumn('config');
        });
    }
};
