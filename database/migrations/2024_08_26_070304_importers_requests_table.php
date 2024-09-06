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
       /*  Schema::create('importers_requests', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('apps_id')->unsigned()->index();
            $table->bigInteger('users_id')->unsigned()->index();
            $table->bigInteger('companies_id')->unsigned()->index();
            $table->bigInteger('regions_id')->unsigned()->index();
            $table->bigInteger('companies_branches_id')->unsigned()->index();
            $table->bigInteger('filesystem_id')->unsigned()->index();
            $table->string('uuid');
            $table->integer('status')->default(0)->index();
            $table->integer('is_deleted')->default(0)->index();
            $table->timestamps();
        }); */
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //Schema::dropIfExists('importers_requests');
    }
};
