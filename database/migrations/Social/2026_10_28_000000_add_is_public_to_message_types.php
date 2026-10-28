<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::connection('social')->hasColumn('message_types', 'is_public')) {
            Schema::connection('social')->table('message_types', function (Blueprint $table) {
                $table->boolean('is_public')->default(true)->after('templates_plura');
            });
        }

        // Snapshot of MessageType::PRIVATE_BY_DEFAULT_VERBS when this ran, inlined on purpose: a migration
        // is frozen and CreateMessageTypeAction covers every row created after this one.
        DB::connection('social')->table('message_types')
            ->whereIn('verb', ['system'])
            ->update(['is_public' => false]);
    }

    public function down(): void
    {
        Schema::connection('social')->table('message_types', function (Blueprint $table) {
            $table->dropColumn('is_public');
        });
    }
};
