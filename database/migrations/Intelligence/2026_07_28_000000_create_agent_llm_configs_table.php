<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'intelligence';

    public function up(): void
    {
        Schema::create('agent_llm_configs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid');
            $table->unsignedBigInteger('apps_id');
            $table->unsignedBigInteger('companies_id')->default(0);
            $table->unsignedBigInteger('users_id')->nullable();
            $table->string('name');
            $table->string('slug');
            $table->string('provider', 50);
            $table->string('base_uri')->nullable();
            // Encrypted at the model layer (casts: api_key => encrypted); never exposed in GraphQL.
            $table->text('api_key')->nullable();
            $table->string('model')->nullable();
            $table->json('config')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();

            $table->index(['apps_id', 'companies_id', 'is_deleted'], 'agent_llm_configs_tenant_index');
            $table->index('uuid');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->dropIfExists('agent_llm_configs');
    }
};
