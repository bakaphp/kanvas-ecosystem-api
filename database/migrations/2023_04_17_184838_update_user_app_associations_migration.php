<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users_associated_apps', function (Blueprint $table) {
            $table->string('displayname', 45)->nullable()->after('password');
            $table->dateTime('lastvisit')->nullable()->after('displayname');
            $table->integer('user_login_tries')->nullable()->after('lastvisit');
            $table->integer('user_last_login_try')->nullable()->after('user_login_tries');
            $table->string('user_activation_key', 64)->nullable()->after('user_last_login_try');
            $table->string('user_activation_forgot', 100)->nullable()->after('user_activation_key');
            $table->boolean('banned')->nullable()->default(false)->after('user_activation_forgot');
            $table->integer('status')->default(1)->after('banned');
            $table->integer('user_recover_code')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // * @property string $displayname
    }
};
