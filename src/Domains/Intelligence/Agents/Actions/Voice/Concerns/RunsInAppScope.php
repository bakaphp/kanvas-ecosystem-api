<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\Voice\Concerns;

use Baka\Contracts\AppInterface;
use Bouncer;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;

/**
 * The voice runtime authenticates with its own app key, which is not always the agent's app, so
 * the data plane switches to the agent's app for the work and puts the caller's back afterwards.
 */
trait RunsInAppScope
{
    /**
     * Role lookups are filtered by Bouncer's current scope. Not Scope::onceTo(): it leaves the
     * scope switched when the callback throws, and these actions catch and carry on.
     */
    protected function inAppScope(AppInterface $app, callable $callback): mixed
    {
        $previousScope = Bouncer::scope()->get();
        Bouncer::scope()->to(RolesEnums::getScope($app));

        try {
            return $callback();
        } finally {
            Bouncer::scope()->to($previousScope);
        }
    }

    /**
     * Also binds the container's app, for code that reads it instead of taking one: CreateLeadAction
     * re-resolves the People through getById(), and a tool resolves whatever it needs.
     */
    protected function inApp(Apps $app, callable $callback): mixed
    {
        $previousApp = app(Apps::class);
        app()->instance(Apps::class, $app);

        try {
            return $this->inAppScope($app, $callback);
        } finally {
            app()->instance(Apps::class, $previousApp);
        }
    }
}
