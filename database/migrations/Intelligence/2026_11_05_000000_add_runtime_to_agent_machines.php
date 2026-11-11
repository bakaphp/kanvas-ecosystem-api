<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\MachineRuntimeEnum;

/**
 * Which process runs opencode on this machine: a container per agent, or a host process per agent.
 * A machine property because it is the box that has (or lacks) Xcode, not the app.
 */
return new class () extends Migration {
    protected $connection = 'intelligence';

    public function up(): void
    {
        Schema::connection('intelligence')->table('agent_machines', function (Blueprint $table): void {
            $table->string('runtime', 16)->default(MachineRuntimeEnum::CONTAINER->value)->after('network_mode');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table('agent_machines', function (Blueprint $table): void {
            $table->dropColumn('runtime');
        });
    }
};
