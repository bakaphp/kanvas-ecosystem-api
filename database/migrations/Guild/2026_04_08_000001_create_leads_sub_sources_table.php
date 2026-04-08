<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('crm')->create('leads_sub_sources', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->integer('apps_id')->index();
            $table->bigInteger('companies_id')->index();
            $table->bigInteger('leads_sources_id')->index();
            $table->string('name', 150);
            $table->dateTime('created_at')->index();
            $table->dateTime('updated_at')->nullable()->index();
            $table->boolean('is_deleted')->default(false)->index();
            $table->index(['apps_id', 'companies_id', 'is_deleted']);
        });
    }

    public function down(): void
    {
        Schema::connection('crm')->dropIfExists('leads_sub_sources');
    }
};
