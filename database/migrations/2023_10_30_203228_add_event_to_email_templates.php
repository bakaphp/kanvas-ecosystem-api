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
        Schema::table('notification_types', function (Blueprint $table) {
            $table->char('verb', 64)->after('name')->nullable()->index()->default(false);
            $table->char('event', 64)->after('verb')->nullable()->index()->default(false);
            $table->integer('template_id')->after('event')->index()->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notification_types', function (Blueprint $table) {
            $table->dropColumn('verb');
            $table->dropColumn('event');
            $table->dropColumn('template_id');
        });
    }
};
