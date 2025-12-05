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
        /*  Schema::create('channel_categories', function (Blueprint $table) {
             $table->id();
             $table->string('name');
             $table->integer('companies_id')->index();
             $table->integer('apps_id')->index();
             $table->boolean('is_deleted')->default(0);
             $table->timestamp('created_at')->index()->useCurrent();
             $table->datetime('updated_at')->nullable()->index();
         }); */
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('channel_categories');
    }
};
