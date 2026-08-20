<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-receiver notification address, mirroring `leads_rotations.leads_rotations_email`.
 *
 * A rotation is a distribution policy (who works the lead); a receiver is a source (which form it
 * came from). Routing a form's leads to its own address is a source concern, so cloning the rotation
 * per form would split the round-robin hit counters to achieve something unrelated to distribution.
 *
 * Holds an optional comma-separated list — `SendLeadEmailsAction::parseExtraEmails()` already splits it.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('crm')->table('leads_receivers', function (Blueprint $table) {
            $table->string('notification_email')->nullable()->after('source_name');
        });
    }

    public function down(): void
    {
        Schema::connection('crm')->table('leads_receivers', function (Blueprint $table) {
            $table->dropColumn('notification_email');
        });
    }
};
