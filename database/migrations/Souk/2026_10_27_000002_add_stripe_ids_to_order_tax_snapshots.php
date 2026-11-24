<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('commerce')->table('order_tax_snapshots', function (Blueprint $table) {
            if (! Schema::connection('commerce')->hasColumn('order_tax_snapshots', 'stripe_session_id')) {
                $table->string('stripe_session_id', 191)->nullable()->after('payment_intent_id');
            }

            if (! Schema::connection('commerce')->hasColumn('order_tax_snapshots', 'stripe_payment_link_id')) {
                $table->string('stripe_payment_link_id', 191)->nullable()->after('stripe_session_id');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('commerce')->table('order_tax_snapshots', function (Blueprint $table) {
            $table->dropColumn(['stripe_session_id', 'stripe_payment_link_id']);
        });
    }
};
