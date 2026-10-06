<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Kanvas\Intelligence\Agents\Neuron\KanvasGenericNeuronAgent;

/**
 * The handler FQCN lives only on the type row, so repointing the row moves every agent on it. The generic
 * agent takes its persona from the type's soul and its tools from the registry, the closest behaviour to
 * what the deleted Types\BaseAgent lineage declared.
 */
return new class () extends Migration {
    private const array LEGACY_HANDLERS = [
        'Kanvas\Intelligence\Agents\Types\BaseAgent',
        'Kanvas\Intelligence\Agents\Types\CRMAgent',
        'Kanvas\Intelligence\Agents\Types\InventoryAgent',
        'Kanvas\Intelligence\Agents\Types\SocialCreatorAgent',
        'Kanvas\Intelligence\Agents\Types\SocialEngagementAgent',
    ];

    public function up(): void
    {
        $types = DB::connection('intelligence')->table('agent_types');

        // `handler` is not indexed: an update filtered on it locks every row it scans, so the rows are
        // resolved first and written by primary key.
        $ids = (clone $types)->whereIn('handler', self::LEGACY_HANDLERS)->pluck('id')->all();

        if ($ids === []) {
            return;
        }

        $types->whereIn('id', $ids)->update(['handler' => KanvasGenericNeuronAgent::class]);
    }

    public function down(): void
    {
        // The legacy classes no longer exist; a row cannot be pointed back at them.
    }
};
