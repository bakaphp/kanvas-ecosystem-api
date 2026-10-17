<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The engine now records every rule-driven activity run, and most activities have no integration
 * company (internal steps, dispatchers, notifications). Those rows carry the rule, the action and
 * the workflow but no integration.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('workflow')->table('entity_integration_history', function (Blueprint $table) {
            $table->dropForeign(['integrations_company_id']);
            $table->dropForeign(['integrations_id']);
            $table->unsignedBigInteger('integrations_company_id')->nullable()->change();
            $table->unsignedBigInteger('integrations_id')->nullable()->change();
            $table->foreign('integrations_company_id')->references('id')->on('integration_companies');
            $table->foreign('integrations_id')->references('id')->on('integrations');
        });
    }

    public function down(): void
    {
        Schema::connection('workflow')->table('entity_integration_history', function (Blueprint $table) {
            $table->dropForeign(['integrations_company_id']);
            $table->dropForeign(['integrations_id']);
            $table->unsignedBigInteger('integrations_company_id')->nullable(false)->change();
            $table->unsignedBigInteger('integrations_id')->nullable(false)->change();
            $table->foreign('integrations_company_id')->references('id')->on('integration_companies');
            $table->foreign('integrations_id')->references('id')->on('integrations');
        });
    }
};
