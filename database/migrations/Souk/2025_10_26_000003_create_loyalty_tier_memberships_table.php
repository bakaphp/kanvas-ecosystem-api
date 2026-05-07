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
        Schema::connection('commerce')->create('loyalty_tier_memberships', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('apps_id');
            $table->unsignedBigInteger('companies_id')->default(0);
            $table->unsignedBigInteger('users_id');
            $table->unsignedBigInteger('loyalty_tiers_id');
            $table->unsignedBigInteger('loyalty_programs_id');
            $table->bigInteger('lifetime_points')->default(0);
            $table->bigInteger('current_points')->default(0);
            $table->timestamp('tier_promoted_at')->nullable();
            $table->timestamps();
            $table->boolean('is_deleted')->default(false)->index();

            $table->unique(['users_id', 'loyalty_programs_id']);
            $table->foreign('loyalty_tiers_id')->references('id')->on('loyalty_tiers')->onDelete('cascade');
            $table->foreign('loyalty_programs_id')->references('id')->on('loyalty_programs')->onDelete('cascade');
            $table->index('users_id');
            $table->index('lifetime_points');
            $table->index('current_points');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('commerce')->dropIfExists('loyalty_tier_memberships');
    }
};
