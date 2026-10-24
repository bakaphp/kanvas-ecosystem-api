<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What provisioning and teardown remember between runs: who owns an inbound identity, which vendor
 * resources several connections share, and which vendor resources are still to be removed.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('communication_connections', function (Blueprint $table) {
            $table->unsignedSmallInteger('setup_attempts')->default(0)->after('provisioning_started_at');
            $table->timestamp('next_setup_at')->nullable()->after('setup_attempts');
            $table->timestamp('first_ready_at')->nullable()->after('next_setup_at');
        });

        Schema::table('communication_requests', function (Blueprint $table) {
            // A reply a caller already filed itself (an agent's, held for approval or not): the
            // delivery is recorded on that message instead of filing a second copy of it.
            $table->unsignedBigInteger('filed_message_id')->nullable()->after('reply_to_message_id');
        });

        // One live owner per vendor-global inbound identity. A row, not a unique index on
        // connections, so a disconnected history row never blocks the next workspace.
        Schema::create('communication_inbound_claims', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('provider', 32);
            $table->string('inbound_identity', 191);
            $table->unsignedBigInteger('connection_id');
            $table->unsignedBigInteger('apps_id');
            $table->unsignedBigInteger('companies_id');
            $table->timestamp('claimed_at');
            $table->timestamps();

            $table->unique(['provider', 'inbound_identity'], 'communication_inbound_claims_identity_unique');
            $table->index('connection_id', 'communication_inbound_claims_connection_index');
        });

        // Keyed by the vendor account and resource, never by an integration row: two rows (two
        // apps, a re-created integration) pointing at one Messaging Service share one registration.
        Schema::create('communication_shared_registrations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('provider', 32);
            $table->string('provider_account_key', 191);
            $table->string('resource_type', 64);
            $table->string('resource_key', 191);
            $table->string('registered_value', 1024)->nullable();
            $table->json('external_ids')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['provider', 'provider_account_key', 'resource_type', 'resource_key'],
                'communication_shared_registrations_resource_unique'
            );
        });

        // Released dependents stay: the release job needs to know which connections' deliveries
        // still keep the resource alive.
        Schema::create('communication_shared_dependents', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('apps_id');
            $table->unsignedBigInteger('companies_id');
            $table->unsignedBigInteger('integrations_company_id');
            $table->unsignedBigInteger('connection_id');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->unique(['registration_id', 'connection_id'], 'communication_shared_dependents_unique');
            $table->index('connection_id', 'communication_shared_dependents_connection_index');
        });

        Schema::create('communication_cleanup_tasks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('apps_id');
            $table->unsignedBigInteger('companies_id');
            $table->unsignedBigInteger('connection_id');
            $table->string('resource_type', 64);
            $table->string('resource_key', 191);
            $table->string('external_id', 191)->nullable();
            $table->string('expected_value', 1024)->nullable();
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['connection_id', 'status'], 'communication_cleanup_tasks_connection_status_index');
            $table->index(['status', 'next_attempt_at'], 'communication_cleanup_tasks_due_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_cleanup_tasks');
        Schema::dropIfExists('communication_shared_dependents');
        Schema::dropIfExists('communication_shared_registrations');
        Schema::dropIfExists('communication_inbound_claims');

        Schema::table('communication_requests', function (Blueprint $table) {
            $table->dropColumn('filed_message_id');
        });

        Schema::table('communication_connections', function (Blueprint $table) {
            $table->dropColumn(['setup_attempts', 'next_setup_at', 'first_ready_at']);
        });
    }
};
