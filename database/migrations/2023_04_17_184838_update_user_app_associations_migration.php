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
            $table->string('displayname', 65)->nullable()->after('password');
            $table->dateTime('lastvisit')->nullable()->after('displayname');
            $table->integer('user_login_tries')->default(0)->after('lastvisit');
            $table->integer('user_last_login_try')->nullable()->after('user_login_tries');
            $table->string('user_activation_key', 64)->nullable()->after('user_last_login_try');
            $table->string('user_activation_forgot', 100)->nullable()->after('user_activation_key');
            $table->tinyInteger('banned')->default(0)->default(false)->after('user_activation_forgot');
            $table->tinyInteger('welcome')->default(0)->after('banned');
            $table->tinyInteger('status')->default(1)->after('welcome');
            $table->string('user_recover_code')->nullable()->after('status');
            $table->integer('session_time')->nullable()->after('status');
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
