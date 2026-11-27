<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        Schema::connection('workflow')->create('integration_imports', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('apps_id');
            $table->unsignedBigInteger('companies_id');
            $table->unsignedBigInteger('integrations_id');
            $table->unsignedBigInteger('integrations_companies_id');
            $table->unsignedBigInteger('users_id');
            $table->string('entity', 64);
            $table->string('status', 16);
            $table->json('options')->nullable();
            $table->unsignedInteger('total')->nullable();
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('created')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->json('errors')->nullable();
            $table->json('summary')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->boolean('is_deleted')->default(false);

            $table->index(
                ['companies_id', 'integrations_id', 'entity', 'id'],
                'integration_imports_latest_index'
            );
        });
    }

    public function down(): void
    {
        Schema::connection('workflow')->dropIfExists('integration_imports');
    }
};
