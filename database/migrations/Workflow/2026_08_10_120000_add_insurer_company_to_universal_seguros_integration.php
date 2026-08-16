<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    protected $connection = 'workflow';

    private const CONFIG = [
        'environment' => ['type' => 'text', 'required' => true],
        'client_id' => ['type' => 'text', 'required' => true],
        'client_secret' => ['type' => 'text', 'required' => true],
        'scopes' => ['type' => 'text', 'required' => false],
        'insurer_companies_id' => ['type' => 'text', 'required' => true],
    ];

    public function up(): void
    {
        $this->setConfig(self::CONFIG);
    }

    public function down(): void
    {
        $config = self::CONFIG;
        unset($config['insurer_companies_id']);

        $this->setConfig($config);
    }

    private function setConfig(array $config): void
    {
        DB::connection('workflow')->table('integrations')
            ->where('name', 'universal_seguros')
            ->where('apps_id', 0)
            ->update([
                'config' => json_encode($config),
                'updated_at' => now(),
            ]);
    }
};
