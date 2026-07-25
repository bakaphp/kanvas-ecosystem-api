<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('crm')->create('duplicate_review_groups', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id');
            $table->uuid('uuid');

            $table->string('entity_type', 191);
            $table->unsignedBigInteger('canonical_id');
            $table->json('member_ids');
            // sha1 of the sorted member_ids — lets the DB enforce "don't duplicate a group with
            // the same members" via a unique index instead of fetching+comparing every existing
            // row in PHP, and stays race-safe if the sweep ever runs concurrently.
            $table->char('signature', 40);
            $table->string('reason', 64);

            $table->enum('status', ['pending', 'merged', 'dismissed', 'expired'])->default('pending');
            $table->unsignedInteger('resolved_by_users_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_target_id')->nullable();

            $table->boolean('is_deleted')->default(false);
            $table->timestamps();

            $table->unique(['apps_id', 'companies_id', 'entity_type', 'signature'], 'drg_signature_uniq');
            $table->index(['apps_id', 'companies_id', 'entity_type', 'status'], 'drg_app_company_entity_status_idx');
            $table->index(['uuid'], 'drg_uuid_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('crm')->dropIfExists('duplicate_review_groups');
    }
};
