<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('kanvas_modules', function (Blueprint $table) {
            $table->boolean('is_internal')->default(false)->after('description');
            $table->boolean('is_default')->default(false)->after('is_internal');
        });

        // Classification of the current catalog — internal=platform infra
        // (hidden from "what modules does this company have" UI but still
        // provisioned); default=every new company auto-gets it.
        $now = now();
        $classification = [
            1  => ['is_internal' => true,  'is_default' => true],  // ECOSYSTEM
            2  => ['is_internal' => false, 'is_default' => true],  // INVENTORY
            3  => ['is_internal' => false, 'is_default' => true],  // CRM
            4  => ['is_internal' => false, 'is_default' => true],  // SOCIAL
            5  => ['is_internal' => true,  'is_default' => true],  // WORKFLOW
            6  => ['is_internal' => true,  'is_default' => true],  // ACTION_ENGINE
            10 => ['is_internal' => false, 'is_default' => true],  // AI
            11 => ['is_internal' => false, 'is_default' => true],  // COMMERCE
        ];

        foreach ($classification as $id => $flags) {
            DB::table('kanvas_modules')->where('id', $id)->update($flags + ['updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::table('kanvas_modules', function (Blueprint $table) {
            $table->dropColumn(['is_internal', 'is_default']);
        });
    }
};
