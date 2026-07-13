<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\Users;

/**
 * Injects the agent's tenant context (app + company) and its acting user into a Neuron tool, so the
 * tool never guesses the tenant from globals (app(Apps::class) / auth()). The agent sets it once with
 * withContext() when it builds its tool list; the typed properties fail loud if a tool is ever invoked
 * without context, rather than silently falling back to whoever happens to be authenticated.
 *
 * Mirrors the Laravel-side HasKanvasContext, plus the acting user (the user a tool acts AS — the
 * agent's own user for a SystemUserAgent).
 */
trait HasKanvasContext
{
    protected Apps $app;
    protected Companies $company;
    protected Users $user;

    public function withContext(Apps $app, Companies $company, Users $user): static
    {
        $this->app = $app;
        $this->company = $company;
        $this->user = $user;

        return $this;
    }
}
