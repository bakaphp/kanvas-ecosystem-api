<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('event')->table('event_version_participants', function (Blueprint $table) {
            $table->string('payment_status', 32)->nullable()->after('invoice_date')->index();
        });
    }

    public function down(): void
    {
        Schema::connection('event')->table('event_version_participants', function (Blueprint $table) {
            $table->dropColumn('payment_status');
        });
    }
};
