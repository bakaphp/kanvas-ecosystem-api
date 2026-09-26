<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('crm')->create('lead_handoff_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id');
            $table->unsignedBigInteger('leads_id');
            $table->unsignedInteger('sequence');
            $table->string('handoff_type', 32);
            $table->timestamp('created_at')->nullable();

            // This table exists for its unique key: it is what makes a handoff claim atomic.
            // apps_custom_fields has no unique index on any column combination, so a counter held
            // there can only ever be a read-modify-write two workers both win.
            $table->unique(['leads_id', 'sequence'], 'lhn_lead_sequence_unique');
            $table->index(['apps_id', 'companies_id', 'leads_id'], 'lhn_app_company_lead_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('crm')->dropIfExists('lead_handoff_notifications');
    }
};
