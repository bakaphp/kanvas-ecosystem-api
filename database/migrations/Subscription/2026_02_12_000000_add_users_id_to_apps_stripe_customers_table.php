<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('apps_stripe_customers', function (Blueprint $table) {
            $table->bigInteger('users_id')->unsigned()->default(0)->after('companies_id')->index();
            $table->json('config')->nullable()->after('trial_ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('apps_stripe_customers', function (Blueprint $table) {
            $table->dropColumn(['users_id', 'config']);
        });
    }
};
