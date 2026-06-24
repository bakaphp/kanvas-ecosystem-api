<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->create('payment_terms', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id');
            $table->uuid('uuid');

            $table->string('name', 64);                              // 'Net 30', 'Due on receipt', '2/10 Net 30'

            $table->unsignedSmallInteger('net_days')->default(0);    // total days until due

            $table->unsignedSmallInteger('discount_days')->nullable();   // e.g. 10 for '2/10 Net 30'
            $table->decimal('discount_pct', 5, 4)->nullable();           // e.g. 0.0200 for 2%

            $table->boolean('is_default')->default(false);

            $table->json('metadata')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->unsignedInteger('users_id')->nullable();
            $table->timestamps();

            $table->unique(['apps_id', 'companies_id', 'name'], 'payment_terms_app_company_name_uq');
            $table->index(['apps_id', 'companies_id', 'is_default'], 'payment_terms_app_company_default_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->dropIfExists('payment_terms');
    }
};
