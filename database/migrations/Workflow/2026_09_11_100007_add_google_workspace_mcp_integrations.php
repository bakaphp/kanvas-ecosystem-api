<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Connectors\Mcp\Handlers\McpHandler;
use Kanvas\Workflow\Enums\IntegrationTypeEnum;

/**
 * Google Workspace's remote MCP servers (Workspace Developer Preview), connected with OAuth only.
 *
 * Google publishes RFC 9728 metadata naming accounts.google.com, so the endpoints are discovered — but it
 * does no dynamic client registration: an admin creates one "Web application" OAuth client and stores it
 * as the app settings `mcp_oauth_client_id_google` / `mcp_oauth_client_secret_google`, which all five
 * servers share through `client_key`. `access_type=offline` + `prompt=consent` are what make Google issue
 * a refresh token. Scopes are the ones Google's configuration guide lists for each server.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    private const array SERVERS = [
        'google_gmail_mcp' => [
            'vendor' => 'Gmail',
            'url' => 'https://gmailmcp.googleapis.com/mcp/v1',
            'prefix' => 'gmail',
            'scopes' => [
                'https://www.googleapis.com/auth/gmail.readonly',
                'https://www.googleapis.com/auth/gmail.compose',
            ],
        ],
        'google_drive_mcp' => [
            'vendor' => 'Google Drive',
            'url' => 'https://drivemcp.googleapis.com/mcp/v1',
            'prefix' => 'gdrive',
            'scopes' => [
                'https://www.googleapis.com/auth/drive.readonly',
                'https://www.googleapis.com/auth/drive.file',
            ],
        ],
        'google_calendar_mcp' => [
            'vendor' => 'Google Calendar',
            'url' => 'https://calendarmcp.googleapis.com/mcp/v1',
            'prefix' => 'gcal',
            'scopes' => [
                'https://www.googleapis.com/auth/calendar.calendarlist.readonly',
                'https://www.googleapis.com/auth/calendar.events.freebusy',
                'https://www.googleapis.com/auth/calendar.events.readonly',
            ],
        ],
        'google_sheets_mcp' => [
            'vendor' => 'Google Sheets',
            'url' => 'https://sheetsmcp.googleapis.com/mcp/v1',
            'prefix' => 'gsheets',
            'scopes' => [
                'https://www.googleapis.com/auth/drive.readonly',
                'https://www.googleapis.com/auth/drive.file',
                'https://www.googleapis.com/auth/spreadsheets.readonly',
                'https://www.googleapis.com/auth/spreadsheets',
            ],
        ],
        'google_people_mcp' => [
            'vendor' => 'Google People',
            'url' => 'https://people.googleapis.com/mcp/v1',
            'prefix' => 'gpeople',
            'scopes' => [
                'https://www.googleapis.com/auth/directory.readonly',
                'https://www.googleapis.com/auth/userinfo.profile',
                'https://www.googleapis.com/auth/contacts.readonly',
            ],
        ],
    ];

    public function up(): void
    {
        foreach (self::SERVERS as $name => $server) {
            $exists = DB::connection('workflow')->table('integrations')
                ->where('name', $name)
                ->where('apps_id', 0)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::connection('workflow')->table('integrations')->insert([
                'uuid' => (string) Str::uuid(),
                'name' => $name,
                'handler' => McpHandler::class,
                'type' => IntegrationTypeEnum::MCP->value,
                'apps_id' => 0,
                'config' => json_encode([]),
                'metadata' => json_encode([
                    'vendor' => $server['vendor'],
                    'url' => $server['url'],
                    'transport' => 'http',
                    'auth_methods' => ['oauth'],
                    'prefix' => $server['prefix'],
                    'exclude' => [],
                    'timeout_ms' => 20000,
                    'oauth' => [
                        'client_key' => 'google',
                        'scopes' => $server['scopes'],
                        'authorize_params' => [
                            'access_type' => 'offline',
                            'prompt' => 'consent',
                        ],
                    ],
                ]),
                'is_deleted' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::connection('workflow')->table('integrations')
            ->whereIn('name', array_keys(self::SERVERS))
            ->where('apps_id', 0)
            ->delete();
    }
};
