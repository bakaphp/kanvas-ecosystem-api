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
        Schema::create('abilities_modules', function (Blueprint $table) {
            $table->id();
            $table->integer('system_modules_id')->index('system_modules_id');
            $table->integer('apps_id')->index('apps_id');
            $table->integer('abilities_id')->index('abilities_id');
            $table->integer('module_id')->index('module_id');
            $table->string('scope');
            $table->integer('is_deleted')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('abilities_modules');
    }
};
