<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Table only; the lead follows that already live in social `users_follows` are copied by
 * `guild:backfill-lead-followers`, outside the deploy.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('crm')->create('followers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('apps_id');
            $table->unsignedBigInteger('companies_id');
            $table->unsignedBigInteger('users_id');
            $table->unsignedBigInteger('entity_id');
            $table->string('entity_namespace');
            $table->timestamps();
            $table->boolean('is_deleted')->default(0);

            $table->unique(['entity_namespace', 'entity_id', 'users_id'], 'uq_followers_entity_user');
            $table->index(['users_id', 'apps_id', 'is_deleted'], 'ix_followers_user');
        });
    }

    public function down(): void
    {
        Schema::connection('crm')->dropIfExists('followers');
    }
};
