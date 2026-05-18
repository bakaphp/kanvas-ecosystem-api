<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('agent_types', function (Blueprint $table) {
            // Mirror the per-column shape used on `agents` (soul/instructions/output_format)
            // so the global template is composable, not a single opaque JSON blob.
            $table->longText('soul')->nullable()->after('role');
            $table->longText('instructions')->nullable()->after('soul');
            $table->longText('output_format')->nullable()->after('instructions');
        });
    }

    public function down(): void
    {
        Schema::table('agent_types', function (Blueprint $table) {
            $table->dropColumn(['soul', 'instructions', 'output_format']);
        });
    }
};
