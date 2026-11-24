<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The quote's current Statement of Work: a Social `sow` message. Each SOW revision is a new message
 * chained by parent_id, so this column only ever points at the newest one.
 *
 * Not a foreign key: messages live on the `social` connection.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->table('quotes', function (Blueprint $table): void {
            $table->unsignedBigInteger('sow_message_id')->nullable()->after('revision_number');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->table('quotes', function (Blueprint $table): void {
            $table->dropColumn('sow_message_id');
        });
    }
};
