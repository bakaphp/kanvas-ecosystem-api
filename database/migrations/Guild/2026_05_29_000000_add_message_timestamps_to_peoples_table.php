<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('crm')->table('peoples', function (Blueprint $table) {
            $table->timestamp('first_message_at')->nullable()->after('updated_at')->index();
            $table->timestamp('last_message_at')->nullable()->after('first_message_at')->index();
        });
    }

    public function down(): void
    {
        Schema::connection('crm')->table('peoples', function (Blueprint $table) {
            $table->dropColumn(['first_message_at', 'last_message_at']);
        });
    }
};
