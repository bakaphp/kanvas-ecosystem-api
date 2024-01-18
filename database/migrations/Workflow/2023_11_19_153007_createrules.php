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
        Schema::create('rules', function (Blueprint $table) {
            $table->id();
            $table->integer('systems_modules_id');
            $table->integer('companies_id')->nullable();
            $table->integer('apps_id')->default(1);
            $table->unsignedBigInteger('rules_types_id')->nullable();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('pattern');
            $table->longText('params');
            $table->tinyInteger('is_async')->default(1);
            $table->timestamps();
            $table->integer('is_deleted')->default(0);

            $table->index('rules_types_id');
            $table->index('systems_modules_id');
            $table->index('companies_id');
            $table->index('created_at');
            $table->index('is_deleted');
            $table->index('apps_id');
            $table->index('is_async');

            $table->foreign('rules_types_id')->references('id')->on('rules_types')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rules');
    }
};
