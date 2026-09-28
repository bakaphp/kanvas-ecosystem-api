<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Atlassian's MCP server connects through `connectNervousSystemMcpServer` (OAuth), so a pasted token is
 * the optional manual path, not the form's one required field.
 *
 * Left required, ConfigValidation rejects an empty form before McpHandler can tell the admin to use the
 * OAuth flow instead. `refresh_token` and `expires_in` join it so a hand-obtained grant can be stored
 * whole — an access token alone dies within the hour with nothing to renew it.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        $this->setConfig([
            'token' => ['type' => 'text', 'required' => false],
            'refresh_token' => ['type' => 'text', 'required' => false],
            'expires_in' => ['type' => 'text', 'required' => false],
        ]);
    }

    public function down(): void
    {
        $this->setConfig([
            'token' => ['type' => 'text', 'required' => true],
        ]);
    }

    /**
     * @param array<string, array<string, mixed>> $config
     */
    private function setConfig(array $config): void
    {
        DB::connection('workflow')->table('integrations')
            ->where('name', 'atlassian_mcp')
            ->where('apps_id', 0)
            ->update([
                'config' => json_encode($config),
                'updated_at' => now(),
            ]);
    }
};
