<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    protected $connection = 'intelligence';

    private const string OLD_HANDLER = 'Kanvas\\Intelligence\\Agents\\Neuron\\CRM\\IntelligenceCRM';
    private const string NEW_HANDLER = 'Kanvas\\Intelligence\\Agents\\Neuron\\CRM\\SalesAgent';

    public function up(): void
    {
        DB::connection('intelligence')
            ->table('agent_types')
            ->where('handler', self::OLD_HANDLER)
            ->update(['handler' => self::NEW_HANDLER, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::connection('intelligence')
            ->table('agent_types')
            ->where('handler', self::NEW_HANDLER)
            ->update(['handler' => self::OLD_HANDLER, 'updated_at' => now()]);
    }
};
