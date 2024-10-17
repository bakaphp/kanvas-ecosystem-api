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
        Schema::table('participants', function (Blueprint $table) {
            $table->unsignedBigInteger('participant_status_id')->nullable()->change();
            $table->string('general_representative')->nullable()->change();
            $table->string('is_prospect')->default(0)->change();
        });

        Schema::table('event_version_date_participants', function (Blueprint $table) {
            $table->unsignedBigInteger('event_version_date_id')->nullable()->change();
            $table->date('arrived')->nullable()->change();
        });

        Schema::table('event_version_participants', function (Blueprint $table) {
            $table->float('ticket_price')->default(0)->change();
            $table->float('discount')->default(0)->change();
            $table->date('invoice_date')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
    }
};
