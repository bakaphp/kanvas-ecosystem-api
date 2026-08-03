<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Repositories;

use Baka\Contracts\AppInterface;
use Kanvas\Intelligence\Agents\Models\Agent;

class AgentsRepository
{
    /**
     * Fetch a single agent by uuid, scoped to the given app.
     *
     * Used by the voice runtime's spec fetch (server-to-server via app key), so
     * one app's credentials can only resolve its own agents. Throws
     * ModelNotFoundException when the uuid does not belong to the app.
     */
    public static function getByUuidFromApp(string $uuid, AppInterface $app): Agent
    {
        return Agent::where('uuid', $uuid)
            ->where('apps_id', $app->getId())
            ->firstOrFail();
    }
}
