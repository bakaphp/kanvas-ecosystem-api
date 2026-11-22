<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('apps_keys_sources', function (Blueprint $table) {
            $table->id();
            $table->char('client_id', 36);
            $table->integer('apps_id')->index();
            $table->string('ip', 45)->index();
            $table->string('user_agent', 255)->nullable();
            $table->dateTime('first_seen_at')->index();
            $table->dateTime('last_seen_at')->index();
            $table->boolean('sentry_alert_pending')->default(false)->index();
            $table->boolean('email_alert_pending')->default(false)->index();

            $table->unique(['client_id', 'ip']);
            $table->index(['client_id', 'first_seen_at']);
        });

        Schema::table('apps_keys', function (Blueprint $table) {
            $table->boolean('alert_on_new_source')->default(false)->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('apps_keys', function (Blueprint $table) {
            $table->dropColumn('alert_on_new_source');
        });

        Schema::dropIfExists('apps_keys_sources');
    }
};
