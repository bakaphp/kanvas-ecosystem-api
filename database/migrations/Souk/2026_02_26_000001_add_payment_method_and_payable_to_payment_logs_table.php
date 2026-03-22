<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('commerce')->table('payment_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('payment_methods_id')->nullable()->after('payments_id');
            $table->unsignedBigInteger('payable_id')->nullable()->after('payment_methods_id');
            $table->string('payable_type')->nullable()->after('payable_id');
        });
    }

    public function down(): void
    {
        Schema::connection('commerce')->table('payment_logs', function (Blueprint $table) {
            $table->dropColumn(['payment_methods_id', 'payable_id', 'payable_type']);
        });
    }
};
