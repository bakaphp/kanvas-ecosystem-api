<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    /**
     * The original classification migration (2026_05_10_310000) ran an UPDATE
     * by hardcoded id, which silently no-ops on environments where the
     * `kanvas_modules` rows get seeded *after* migrations (CI fresh DB
     * scenario). Re-apply the classification idempotently — UPDATE if the
     * row already exists, INSERT if it doesn't — so the flags are correct
     * regardless of seeding order.
     *
     * @var array<int, array{name: string, is_internal: bool, is_default: bool}>
     */
    private array $classification = [
        1  => ['name' => 'Ecosystem',      'is_internal' => true,  'is_default' => true],
        2  => ['name' => 'Inventory',      'is_internal' => false, 'is_default' => true],
        3  => ['name' => 'CRM',            'is_internal' => false, 'is_default' => true],
        4  => ['name' => 'Knowledge base', 'is_internal' => false, 'is_default' => true],
        5  => ['name' => 'WORKFLOW',       'is_internal' => true,  'is_default' => true],
        6  => ['name' => 'Action Engine',  'is_internal' => true,  'is_default' => true],
        10 => ['name' => 'AI',             'is_internal' => false, 'is_default' => true],
        11 => ['name' => 'Commerce',       'is_internal' => false, 'is_default' => true],
    ];

    public function up(): void
    {
        $now = now();
        foreach ($this->classification as $id => $row) {
            $exists = DB::table('kanvas_modules')->where('id', $id)->exists();
            if ($exists) {
                DB::table('kanvas_modules')->where('id', $id)->update([
                    'is_internal' => $row['is_internal'],
                    'is_default' => $row['is_default'],
                    'updated_at' => $now,
                ]);
                continue;
            }
            DB::table('kanvas_modules')->insert([
                'id' => $id,
                'name' => $row['name'],
                'is_internal' => $row['is_internal'],
                'is_default' => $row['is_default'],
                'is_deleted' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
    }
};
