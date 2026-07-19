<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Builders;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\AgentConfigBackup;

class AgentConfigBackupBuilder
{
    public function build(mixed $root, array $args): Builder
    {
        $app = app(Apps::class);

        $query = AgentConfigBackup::where('apps_id', $app->getId())
            ->where('is_deleted', 0);

        if (isset($args['agent_id'])) {
            $query->where('agents_id', (int) $args['agent_id']);
        }

        return $query->orderByDesc('id');
    }
}
