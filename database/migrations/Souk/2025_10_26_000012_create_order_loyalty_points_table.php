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
        Schema::connection('commerce')->create('order_loyalty_points', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('apps_id');
            $table->unsignedBigInteger('companies_id')->default(0);
            $table->unsignedBigInteger('orders_id');
            $table->unsignedBigInteger('loyalty_programs_id');
            $table->unsignedBigInteger('loyalty_tier_memberships_id');
            $table->integer('points_earned')->default(0);
            $table->integer('points_redeemed')->default(0);
            $table->integer('points_net')->storedAs('points_earned - points_redeemed');
            $table->enum('status', ['pending', 'credited', 'reversed'])->default('pending');
            $table->timestamp('credited_at')->nullable();
            $table->timestamps();
            $table->boolean('is_deleted')->default(false)->index();

            $table->foreign('orders_id')->references('id')->on('orders')->onDelete('cascade');
            $table->foreign('loyalty_programs_id')->references('id')->on('loyalty_programs')->onDelete('cascade');
            $table->foreign('loyalty_tier_memberships_id')->references('id')->on('loyalty_tier_memberships')->onDelete('cascade');

            $table->index('orders_id');
            $table->index('loyalty_programs_id');
            $table->index('status');
            $table->index('credited_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('commerce')->dropIfExists('order_loyalty_points');
    }
};
