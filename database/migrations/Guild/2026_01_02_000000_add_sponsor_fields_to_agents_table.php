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
        Schema::connection('crm')->table('agents', function (Blueprint $table) {
            $table->string('sponsor_name')->nullable()->after('owner_linked_source_id');
            $table->bigInteger('sponsor_user_id')->unsigned()->nullable()->after('sponsor_name');
            $table->index('sponsor_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('crm')->table('agents', function (Blueprint $table) {
            $table->dropIndex(['sponsor_user_id']);
            $table->dropColumn(['sponsor_name', 'sponsor_user_id']);
        });
    }
};
