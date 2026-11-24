<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * These live beside integration_companies on purpose: a connection is written in the same
     * transaction as the integration that selects its account, and turning a connection off cancels
     * its pending deliveries in one transaction too. Neither is possible across two connections.
     */
    public function up(): void
    {
        Schema::create('communication_connections', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('apps_id');
            $table->unsignedBigInteger('companies_id');
            $table->unsignedBigInteger('integrations_company_id')->index();
            $table->string('provider', 32);
            $table->string('account_key', 191);
            $table->string('label', 191)->nullable();
            $table->json('config')->nullable();
            $table->string('setup_status', 32)->default('pending_setup')->index();
            $table->string('health', 16)->default('unknown');
            $table->timestamp('last_probed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('activation_version')->default(0);
            $table->json('provisioning')->nullable();
            $table->timestamp('provisioning_started_at')->nullable();
            $table->timestamp('disconnect_requested_at')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();

            // NULL once the row is history, so reconnecting an account inserts a new row while the
            // old one keeps its deliveries: MySQL lets any number of NULLs sit under a unique index.
            $table->string('active_key', 191)
                ->nullable()
                ->virtualAs("IF(setup_status IN ('disconnecting', 'disconnected'), NULL, account_key)");
            $table->unique(
                ['apps_id', 'companies_id', 'provider', 'active_key'],
                'communication_connections_active_unique'
            );
        });

        Schema::create('communication_channel_defaults', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('apps_id');
            $table->unsignedBigInteger('companies_id');
            $table->string('channel', 16);
            $table->unsignedBigInteger('connection_id')->index();
            $table->timestamps();

            $table->unique(['apps_id', 'companies_id', 'channel'], 'communication_channel_defaults_unique');
        });

        Schema::create('communication_requests', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('apps_id');
            $table->unsignedBigInteger('companies_id');
            $table->unsignedBigInteger('users_id')->nullable();
            $table->string('kind', 16);
            // Null only on a reply in a conversation filed on something that is not a person.
            $table->unsignedBigInteger('people_id')->nullable();
            $table->json('content');
            $table->json('sender')->nullable();
            $table->json('channels');
            $table->string('idempotency_key', 191)->nullable();
            $table->string('tag', 64)->nullable();
            $table->unsignedBigInteger('reply_to_message_id')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();

            $table->unique(
                ['apps_id', 'companies_id', 'idempotency_key'],
                'communication_requests_idempotency_unique'
            );
            $table->index('people_id', 'communication_requests_people_index');
        });

        Schema::create('communication_deliveries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('apps_id');
            $table->unsignedBigInteger('companies_id');
            $table->unsignedBigInteger('request_id')->index();
            $table->unsignedBigInteger('connection_id')->nullable();
            $table->string('channel', 16);
            $table->string('provider', 32)->nullable();
            $table->string('to_address', 191)->nullable();
            $table->string('status', 16);
            $table->string('external_id', 191)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error')->nullable();
            $table->json('warnings')->nullable();
            $table->unsignedInteger('activation_version')->default(0);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedBigInteger('message_id')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->timestamp('events_closed_at')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();

            $table->index(['provider', 'external_id'], 'communication_deliveries_external_index');
            $table->index(['connection_id', 'status'], 'communication_deliveries_connection_status_index');
            $table->index(['status', 'events_closed_at'], 'communication_deliveries_outstanding_index');
        });

        Schema::create('communication_delivery_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('apps_id');
            $table->unsignedBigInteger('companies_id');
            $table->unsignedBigInteger('delivery_id')->index();
            $table->string('provider_status', 32);
            $table->char('event_key', 64)->unique();
            $table->string('error_code', 64)->nullable();
            $table->text('error')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_delivery_events');
        Schema::dropIfExists('communication_deliveries');
        Schema::dropIfExists('communication_requests');
        Schema::dropIfExists('communication_channel_defaults');
        Schema::dropIfExists('communication_connections');
    }
};
