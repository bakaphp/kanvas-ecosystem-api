<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    private const CONNECTION = 'intelligence';

    public function up(): void
    {
        // Durable dedup ledger for project ingests (transcripts/emails/mentions). One row per unique
        // (project, content) so a re-submitted transcript is caught even if the Redis fast-path cache
        // was flushed. Append-only.
        Schema::connection(self::CONNECTION)->create('nervous_system_project_ingests', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('apps_id');
            $table->unsignedBigInteger('companies_id');
            $table->unsignedBigInteger('project_id');
            $table->char('content_hash', 40);
            $table->string('ingest_type', 20);
            $table->unsignedBigInteger('message_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['project_id', 'content_hash'], 'nspi_project_content');
            $table->index(['apps_id', 'companies_id'], 'nspi_tenant');
        });
    }

    public function down(): void
    {
        Schema::connection(self::CONNECTION)->dropIfExists('nervous_system_project_ingests');
    }
};
