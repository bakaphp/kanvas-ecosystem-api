<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem\Agents\Coding\Concerns;

use App\Console\Commands\Concerns\ResolvesAppOption;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\AgentMachine;

/**
 * The `--app` / `--machine` / `--all` trio every command that acts on an app's coding machines takes.
 */
trait SelectsAppMachines
{
    use ResolvesAppOption;

    /**
     * @return list<AgentMachine>
     */
    private function machines(Apps $app): array
    {
        if ($this->option('all')) {
            return AgentMachine::query()
                ->fromApp($app)
                ->notDeleted()
                ->where('is_active', 1)
                ->get()
                ->all();
        }

        $machineId = $this->option('machine');

        if ($machineId === null) {
            return [];
        }

        /** @var AgentMachine|null $machine */
        $machine = AgentMachine::query()->where('id', (int) $machineId)->fromApp($app)->notDeleted()->first();

        return $machine === null ? [] : [$machine];
    }
}
