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
        Schema::connection('commerce')->create('referral_codes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('apps_id');
            $table->unsignedBigInteger('companies_id')->default(0);
            $table->unsignedBigInteger('users_id');
            $table->string('code', 50)->unique();
            $table->unsignedBigInteger('loyalty_programs_id');
            $table->unsignedBigInteger('discounts_id')->nullable();

            // Rewards
            $table->integer('referrer_reward')->default(500);
            $table->integer('referee_reward')->default(100);
            $table->decimal('referee_discount', 5, 2)->default(10.0);

            // Usage tracking
            $table->integer('max_uses')->nullable();
            $table->integer('current_uses')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);

            // Strategy
            $table->enum('strategy', ['single', 'multiple'])->default('single');

            // Metadata
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->boolean('is_deleted')->default(false)->index();

            $table->foreign('loyalty_programs_id')->references('id')->on('loyalty_programs')->onDelete('cascade');

            $table->index('code');
            $table->index('users_id');
            $table->index('loyalty_programs_id');
            $table->index('discounts_id');
            $table->index('is_active');
            $table->index('strategy');
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('commerce')->dropIfExists('referral_codes');
    }
};
