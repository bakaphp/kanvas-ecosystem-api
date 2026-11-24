<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        $this->setConfig([
            'api_key' => ['type' => 'password', 'required' => true],
            'license' => ['type' => 'text', 'required' => true],
            'branch' => ['type' => 'text', 'required' => true],
            'pos' => ['type' => 'text', 'required' => true],
            'rnc' => ['type' => 'text', 'required' => true],
            'environment' => ['type' => 'text', 'required' => false],
        ]);
    }

    public function down(): void
    {
        $this->setConfig([
            'username' => ['type' => 'text', 'required' => true],
            'password' => ['type' => 'password', 'required' => true],
            'license' => ['type' => 'text', 'required' => true],
            'branch' => ['type' => 'text', 'required' => true],
            'pos' => ['type' => 'text', 'required' => true],
            'rnc' => ['type' => 'text', 'required' => true],
            'environment' => ['type' => 'text', 'required' => false],
        ]);
    }

    private function setConfig(array $config): void
    {
        DB::connection('workflow')->table('integrations')
            ->where('name', 'webpos')
            ->where('apps_id', 0)
            ->update(['config' => json_encode($config), 'updated_at' => now()]);
    }
};
