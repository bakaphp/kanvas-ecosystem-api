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
        Schema::connection('commerce')->table('carts', function (Blueprint $table) {
            // Add loyalty-related fields if they don't exist
            if (! Schema::connection('commerce')->hasColumn('carts', 'recovery_discount_id')) {
                $table->unsignedBigInteger('recovery_discount_id')->nullable()->after('status');
            }
            
            if (! Schema::connection('commerce')->hasColumn('carts', 'recovery_email_sent_at')) {
                $table->timestamp('recovery_email_sent_at')->nullable()->after('recovery_discount_id');
            }
            
            if (! Schema::connection('commerce')->hasColumn('carts', 'recovered_at')) {
                $table->timestamp('recovered_at')->nullable()->after('recovery_email_sent_at');
            }
            
            if (! Schema::connection('commerce')->hasColumn('carts', 'recovered_order_id')) {
                $table->unsignedBigInteger('recovered_order_id')->nullable()->after('recovered_at');
            }
            
            if (! Schema::connection('commerce')->hasColumn('carts', 'abandoned_at')) {
                $table->timestamp('abandoned_at')->nullable()->after('recovered_order_id');
            }
            
            // Add foreign keys
            $table->foreign('recovery_discount_id')->references('id')->on('discounts')->onDelete('set null');
            $table->foreign('recovered_order_id')->references('id')->on('orders')->onDelete('set null');
            
            // Add indexes
            $table->index('recovery_email_sent_at');
            $table->index('recovered_at');
            $table->index('abandoned_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('commerce')->table('carts', function (Blueprint $table) {
            $table->dropForeign(['recovery_discount_id']);
            $table->dropForeign(['recovered_order_id']);
            $table->dropColumn([
                'recovery_discount_id',
                'recovery_email_sent_at',
                'recovered_at',
                'recovered_order_id',
                'abandoned_at',
            ]);
        });
    }
};