<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Registers the two DR e-CF providers against the generic integration mechanism, so the shared
 * `integrationCompany` mutation can set them up from the UI. Alanube's form connects a company that already
 * exists there; onboarding with a certificate stays on `kanvas:alanube-setup`, because a certificate must
 * never be stored in an integration config.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        $this->register('webpos', 'Kanvas\\Connectors\\WebPos\\Handlers\\WebPosHandler', [
            'api_key' => ['type' => 'password', 'required' => true],
            'license' => ['type' => 'text', 'required' => true],
            'branch' => ['type' => 'text', 'required' => true],
            'pos' => ['type' => 'text', 'required' => true],
            'rnc' => ['type' => 'text', 'required' => true],
            'environment' => ['type' => 'text', 'required' => false],
        ]);

        $this->register('alanube', 'Kanvas\\Connectors\\Alanube\\Handlers\\AlanubeHandler', [
            'account_mode' => ['type' => 'text', 'required' => false],
            'api_token' => ['type' => 'password', 'required' => false],
            'alanube_company_id' => ['type' => 'text', 'required' => false],
        ]);
    }

    public function down(): void
    {
        DB::connection('workflow')->table('integrations')
            ->whereIn('name', ['webpos', 'alanube'])
            ->where('apps_id', 0)
            ->delete();
    }

    private function register(string $name, string $handler, array $config): void
    {
        if (DB::connection('workflow')->table('integrations')->where('name', $name)->where('apps_id', 0)->exists()) {
            return;
        }

        DB::connection('workflow')->table('integrations')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'handler' => $handler,
            'apps_id' => 0,
            'config' => json_encode($config),
            'type' => 'key',
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
