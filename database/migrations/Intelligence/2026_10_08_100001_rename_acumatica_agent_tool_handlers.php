<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    private const string LEGACY_PREFIX = 'Kanvas\\Intelligence\\Agents\\Neuron\\Tools\\Acumatica\\';

    private const string ACCOUNTING_PREFIX = 'Kanvas\\Intelligence\\Agents\\Neuron\\Tools\\Accounting\\';

    private const string CONNECTOR_PREFIX = 'Kanvas\\Connectors\\Acumatica\\';

    protected $connection = 'intelligence';

    public function up(): void
    {
        $tools = DB::connection('intelligence')->table('nervous_system_tools')
            ->whereRaw("handler LIKE ? ESCAPE '!'", [self::LEGACY_PREFIX . '%'])
            ->get(['id', 'handler']);

        foreach ($tools as $tool) {
            $suffix = substr($tool->handler, strlen(self::LEGACY_PREFIX));
            $connectorHandler = self::CONNECTOR_PREFIX . $suffix;
            $accountingHandler = self::ACCOUNTING_PREFIX . $suffix;
            $handler = class_exists($connectorHandler) ? $connectorHandler : $accountingHandler;

            DB::connection('intelligence')->table('nervous_system_tools')
                ->where('id', $tool->id)
                ->update(['handler' => $handler]);
        }
    }

    public function down(): void
    {
        // Accounting already had native tool handlers before this migration. Rewriting every
        // Accounting-prefixed row back would corrupt those unrelated catalog entries.
    }
};
