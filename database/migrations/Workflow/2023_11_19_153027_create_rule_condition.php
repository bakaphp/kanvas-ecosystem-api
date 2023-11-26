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
        Schema::create('rules_conditions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('rules_id')->nullable();
            $table->string('attribute_name');
            $table->string('operator');
            $table->text('value');
            $table->integer('is_custom_attributes')->default(0);
            $table->timestamps();
            $table->integer('is_deleted')->default(0);

            $table->index('rules_id');
            $table->index('is_custom_attributes');
            $table->index('created_at');
            $table->index('is_deleted');

            $table->foreign('rules_id')->references('id')->on('rules')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rules_conditions');
    }
};
