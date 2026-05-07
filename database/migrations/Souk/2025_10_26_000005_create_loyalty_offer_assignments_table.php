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
        Schema::connection('commerce')->create('loyalty_offer_assignments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('apps_id');
            $table->unsignedBigInteger('companies_id')->default(0);
            $table->unsignedBigInteger('users_id');
            $table->unsignedBigInteger('loyalty_offers_id');
            $table->enum('status', ['assigned', 'viewed', 'accepted', 'expired'])->default('assigned');
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->boolean('is_deleted')->default(false)->index();

            $table->unique(['users_id', 'loyalty_offers_id']);
            $table->foreign('loyalty_offers_id')->references('id')->on('loyalty_offers')->onDelete('cascade');
            $table->index('users_id');
            $table->index('status');
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('commerce')->dropIfExists('loyalty_offer_assignments');
    }
};
