<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        $config = json_encode([
            'username' => ['type' => 'text', 'required' => true],
            'password' => ['type' => 'password', 'required' => true],
            'account_number' => ['type' => 'text', 'required' => true],
            'origin_company_name' => ['type' => 'text', 'required' => false],
            'origin_contact_name' => ['type' => 'text', 'required' => false],
            'origin_phone' => ['type' => 'text', 'required' => false],
            'origin_email' => ['type' => 'text', 'required' => false],
            'origin_address_line1' => ['type' => 'text', 'required' => false],
            'origin_city' => ['type' => 'text', 'required' => false],
            'origin_country_code' => ['type' => 'text', 'required' => false],
            'origin_postal_code' => ['type' => 'text', 'required' => false],
            'base_url' => ['type' => 'text', 'required' => false],
            'signature_service' => ['type' => 'text', 'required' => false],
            'label_template' => ['type' => 'text', 'required' => false],
            'request_pickup' => ['type' => 'text', 'required' => false],
            'pickup_close_time' => ['type' => 'text', 'required' => false],
            'content_description' => ['type' => 'text', 'required' => false],
            'incoterm' => ['type' => 'text', 'required' => false],
        ]);

        $integrations = DB::connection('workflow')->table('integrations');

        if ($integrations->clone()->where('name', 'dhl')->where('apps_id', 0)->exists()) {
            $integrations->clone()->where('name', 'dhl')->where('apps_id', 0)->update([
                'config' => $config,
                'type' => 'key',
                'updated_at' => now(),
            ]);

            return;
        }

        $integrations->insert([
            'uuid' => (string) Str::uuid(),
            'name' => 'dhl',
            'handler' => 'Kanvas\Connectors\Dhl\Handlers\DhlHandler',
            'apps_id' => 0,
            'config' => $config,
            'type' => 'key',
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
    }
};
