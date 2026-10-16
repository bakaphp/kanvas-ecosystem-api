<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What agents have learned about a repository, distilled into individual statements.
 *
 * Separate from the raw handoff on the session because they answer different questions. A handoff is
 * "what happened in that run" and ages out; a memory is "what is true about this codebase" and should
 * outlive every session, container and task that produced it.
 *
 * Splitting them also makes curation possible: a single wrong sentence can be retired without throwing
 * away the record of the run it came from.
 */
return new class () extends Migration {
    protected $connection = 'intelligence';

    public function up(): void
    {
        Schema::connection('intelligence')->create('coding_repository_memories', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedInteger('apps_id');
            $table->unsignedBigInteger('companies_id');
            $table->string('repo_slug', 191);
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('source_session_id')->nullable();

            $table->string('category', 32);
            $table->text('content');
            // Deduplication key: the same lesson gets reported on almost every run, and five copies of
            // "this repo has no test framework" is five copies of the same tokens every session after.
            $table->char('content_hash', 40);
            $table->string('status', 16)->default('active');
            $table->unsignedInteger('times_reported')->default(1);
            $table->timestamp('last_reported_at')->nullable();

            $table->boolean('is_deleted')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['apps_id', 'companies_id', 'repo_slug', 'content_hash'], 'coding_memories_dedupe_uq');
            $table->index(['companies_id', 'repo_slug', 'status'], 'coding_memories_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->dropIfExists('coding_repository_memories');
    }
};
