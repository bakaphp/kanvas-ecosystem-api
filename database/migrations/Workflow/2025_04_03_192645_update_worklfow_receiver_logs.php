<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('receiver_webhook_calls', function (Blueprint $table) {
            $table->index(['receiver_webhooks_id', 'is_deleted', 'created_at'], 'idx_webhook_join_sort');
        });

        Schema::table('receiver_webhooks', function (Blueprint $table) {
            $table->index(['apps_id', 'companies_id', 'is_deleted'], 'idx_apps_company_active');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('receiver_webhook_calls', function (Blueprint $table) {
            $table->dropIndex('idx_webhook_join_sort');
        });

        Schema::table('receiver_webhooks', function (Blueprint $table) {
            $table->dropIndex('idx_apps_company_active');
        });
    }
};
