<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('crm')->table('peoples_contacts', function (Blueprint $table) {
            $table->string('validation_status', 20)->default('valid')->after('is_opt_out')->index('validation_status');
            $table->timestamp('bounced_at')->nullable()->after('validation_status');
        });
    }

    public function down(): void
    {
        Schema::connection('crm')->table('peoples_contacts', function (Blueprint $table) {
            $table->dropColumn(['validation_status', 'bounced_at']);
        });
    }
};
