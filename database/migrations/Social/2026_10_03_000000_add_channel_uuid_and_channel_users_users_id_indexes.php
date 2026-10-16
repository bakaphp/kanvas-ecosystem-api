<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `channels.uuid` is a public identifier (`createMessage` takes `channel_uuid`, `channels` filters on
 * UUID) and had no index, so each lookup scanned the whole table. `channel_users` is keyed
 * (channel_id, users_id) only, so the user side of the membership pivot (`Users::channels()`) scanned
 * every row.
 */
return new class () extends Migration {
    private const INDEXES = [
        'channels' => [
            'idx_channels_uuid' => ['uuid'],
        ],
        'channel_users' => [
            'idx_channel_users_users_id_channel_id' => ['users_id', 'channel_id'],
        ],
    ];

    public function getConnection(): ?string
    {
        return 'social';
    }

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                if (Schema::connection('social')->hasIndex($table, $name)) {
                    continue;
                }

                Schema::connection('social')->table($table, function (Blueprint $blueprint) use ($columns, $name) {
                    $blueprint->index($columns, $name);
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach (array_keys($indexes) as $name) {
                if (! Schema::connection('social')->hasIndex($table, $name)) {
                    continue;
                }

                Schema::connection('social')->table($table, function (Blueprint $blueprint) use ($name) {
                    $blueprint->dropIndex($name);
                });
            }
        }
    }
};
