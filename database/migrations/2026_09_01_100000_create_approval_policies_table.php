<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What "approving an X" means for a tenant: who signs, in what order, and what runs on success.
 *
 * This table is why a new approvable entity is a row rather than a PHP `match` arm. companies_id 0 is
 * an app-wide default; a company-specific row for the same (system module, approval type) wins.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('approval_policies', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id')->default(0);
            $table->unsignedInteger('system_modules_id');
            $table->string('approval_type', 64);

            $table->json('steps');
            $table->string('handler', 255)->nullable();

            $table->string('trigger', 32)->default('manual');
            $table->json('trigger_condition')->nullable();
            $table->string('trigger_event', 64)->nullable();

            $table->string('reject_policy', 16)->default('any');
            $table->string('fallback_resolver', 64)->nullable();
            $table->json('fallback_config')->nullable();

            // A step resolving to a 40-person role would otherwise DM all 40, per request.
            $table->string('notify', 32)->default('all');
            $table->unsignedInteger('expires_after_hours')->nullable();

            $table->boolean('is_deleted')->default(false);
            $table->timestamps();

            $table->index(
                ['apps_id', 'companies_id', 'system_modules_id', 'approval_type', 'is_deleted'],
                'ap_policy_lookup_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_policies');
    }
};
