<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    private const string INDEX_NAME = 'peoples_address_peoples_id_is_deleted_idx';

    public function up(): void
    {
        Schema::connection('crm')->table('peoples_address', function (Blueprint $table) {
            $table->index(['peoples_id', 'is_deleted'], self::INDEX_NAME);
        });
    }

    public function down(): void
    {
        Schema::connection('crm')->table('peoples_address', function (Blueprint $table) {
            $table->dropIndex(self::INDEX_NAME);
        });
    }
};
