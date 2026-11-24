<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('intelligence')->table('agents', function (Blueprint $table) {
            $table->json('sms_config')->nullable()->after('voice_config');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table('agents', function (Blueprint $table) {
            $table->dropColumn('sms_config');
        });
    }
};
