<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Registers pi.dev (coding-agent job runner) against the generic integration mechanism. Infra setup
 * runs through the shared `integrationCompany` mutation, which reads `handler` from this row and
 * calls PiDevHandler::setup() — storing base_url (app) + api_token (company). Per-agent config
 * (GitHub token + repo allow-list) is handled separately by the piDevConfigureAgent mutation.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        if (DB::connection('workflow')->table('integrations')->where('name', 'pidev')->where('apps_id', 0)->exists()) {
            return;
        }

        $config = [
            'base_url' => ['type' => 'text', 'required' => true],
            'api_token' => ['type' => 'text', 'required' => true],
        ];

        DB::connection('workflow')->table('integrations')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => 'pidev',
            'handler' => 'Kanvas\\Connectors\\PiDev\\Handlers\\PiDevHandler',
            'apps_id' => 0,
            'config' => json_encode($config),
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::connection('workflow')->table('integrations')
            ->where('name', 'pidev')
            ->where('apps_id', 0)
            ->delete();
    }
};
