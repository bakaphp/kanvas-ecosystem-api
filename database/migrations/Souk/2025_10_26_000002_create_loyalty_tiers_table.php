<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('commerce')->create('loyalty_tiers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('loyalty_programs_id');
            $table->bigInteger('companies_id')->default(0)->index();
            $table->string('name');
            $table->integer('level')->default(1);
            $table->integer('min_points')->default(0);
            $table->decimal('earning_multiplier', 3, 2)->default(1.00);
            $table->json('benefits')->nullable();
            $table->timestamps();
            $table->boolean('is_deleted')->default(false)->index();
            
            $table->foreign('loyalty_programs_id')->references('id')->on('loyalty_programs')->onDelete('cascade');
            $table->index('loyalty_programs_id');
            $table->index('level');
            $table->index('min_points');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('commerce')->dropIfExists('loyalty_tiers');
    }
};