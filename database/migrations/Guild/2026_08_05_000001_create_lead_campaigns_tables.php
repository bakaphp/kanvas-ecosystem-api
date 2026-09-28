<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('crm')->create('lead_campaigns', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id');
            $table->uuid('uuid');
            $table->unsignedInteger('users_id'); // manager who initiated the batch

            $table->string('channel', 16); // sms | email
            $table->string('subject', 255)->nullable(); // email subject line
            $table->text('message');
            $table->json('criteria')->nullable(); // interpreted trait filters, for the history view
            $table->enum('status', ['scheduled', 'sending', 'sent', 'failed'])->default('sending');
            $table->timestamp('scheduled_at')->nullable();

            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);

            $table->boolean('is_deleted')->default(false);
            $table->timestamps();

            $table->index(['apps_id', 'companies_id', 'status', 'created_at'], 'lc_app_company_status_idx');
            $table->index(['status', 'scheduled_at'], 'lc_status_scheduled_idx');
            $table->index(['uuid'], 'lc_uuid_idx');
        });

        Schema::connection('crm')->create('lead_campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id');
            $table->uuid('uuid');
            $table->unsignedBigInteger('lead_campaigns_id');
            $table->unsignedBigInteger('leads_id');
            $table->unsignedBigInteger('peoples_id')->nullable();

            $table->enum('status', ['pending', 'sent', 'failed', 'skipped'])->default('pending');
            $table->string('reason', 64)->nullable(); // exclusion / failure reason
            $table->string('destination', 191)->nullable(); // phone/email actually used, for audit
            $table->timestamp('sent_at')->nullable();

            $table->boolean('is_deleted')->default(false);
            $table->timestamps();

            $table->index(['lead_campaigns_id', 'status'], 'lcr_campaign_status_idx');
            $table->index(['leads_id'], 'lcr_leads_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('crm')->dropIfExists('lead_campaign_recipients');
        Schema::connection('crm')->dropIfExists('lead_campaigns');
    }
};
