<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUsersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('users', function (Blueprint $table) {
            $table->integer('id', true);
            $table->char('uuid', 36)->nullable()->index('uuid');
            $table->string('email')->index('email');
            $table->string('password');
            $table->string('firstname', 45)->nullable();
            $table->string('lastname', 45)->nullable();
            $table->mediumText('description')->nullable();
            $table->integer('roles_id')->default(1)->index('roles_id');
            $table->string('displayname', 45)->nullable()->index('displayname');
            $table->integer('default_company')->index('default_company');
            $table->integer('default_company_branch')->nullable()->index('default_company_branch');
            $table->unsignedInteger('city_id')->nullable()->index('city_id');
            $table->unsignedInteger('state_id')->nullable()->index('state_id');
            $table->unsignedInteger('country_id')->nullable()->index('country_id');
            $table->dateTime('registered')->nullable()->index('registered');
            $table->dateTime('lastvisit')->nullable()->index('lastvisit');
            $table->enum('sex', ['U', 'M', 'F'])->nullable()->default('U');
            $table->date('dob')->nullable()->index('dob');
            $table->string('timezone', 128)->nullable()->default('America/New_York')->index('timezone');
            $table->string('phone_number', 128)->nullable()->default('');
            $table->string('cell_phone_number', 128)->nullable()->default('');
            $table->boolean('profile_privacy')->nullable()->default(false);
            $table->string('profile_image', 45)->nullable();
            $table->string('profile_header', 192)->nullable();
            $table->string('profile_header_mobile', 192)->nullable();
            $table->integer('user_active');
            $table->integer('user_login_tries')->nullable()->index('user_login_tries');
            $table->integer('user_last_login_try')->nullable()->index('user_last_login_try');
            $table->integer('session_time')->nullable()->index('session_time');
            $table->integer('session_page')->nullable()->index('session_page');
            $table->integer('welcome')->nullable()->default(0)->index('welcome');
            $table->string('user_activation_key', 64)->nullable()->index('user_activation_key');
            $table->string('user_activation_email', 128)->nullable()->index('user_activation_email');
            $table->string('user_activation_forgot', 100)->nullable()->index('user_activation_forgot');
            $table->string('language', 5)->nullable();
            $table->integer('karma')->nullable();
            $table->unsignedInteger('votes')->nullable();
            $table->integer('votes_points')->nullable();
            $table->boolean('banned')->nullable()->default(false)->index('banned');
            $table->integer('system_modules_id')->nullable();
            $table->integer('status')->default(1)->index('status');
            $table->text('address_1')->nullable();
            $table->text('address_2')->nullable();
            $table->text('zip_code')->nullable();
            $table->integer('user_recover_code')->nullable()->index('user_recover_code');
            $table->dateTime('created_at')->nullable()->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->integer('is_deleted')->default(0)->index('is_deleted');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('users');
    }
}
