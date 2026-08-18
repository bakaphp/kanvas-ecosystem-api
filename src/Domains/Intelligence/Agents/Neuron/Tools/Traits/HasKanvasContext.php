<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\Users;

trait HasKanvasContext
{
    protected Apps $app;
    protected Companies $company;
    protected Users $user;

    public function withContext(
        Apps $app,
        Companies $company,
        Users $user
    ): static {
        $this->app = $app;
        $this->company = $company;
        $this->user = $user;

        return $this;
    }

    /**
     * The acting user when one is set. Registry-resolved tools only get withContext() when all three
     * dependencies were available, so a tool that writes attributed records needs the null path.
     */
    protected function contextUser(): ?Users
    {
        return isset($this->user) ? $this->user : null;
    }

    /**
     * Whether this tool knows which tenant it is acting for.
     *
     * The properties are typed and non-nullable, so reading one without context is a fatal rather
     * than a null — every tool has to ask before it touches them. Named here so the check reads as
     * one question instead of a pair of issets repeated at eight call sites.
     */
    protected function hasTenantContext(): bool
    {
        return isset($this->app) && isset($this->company);
    }
}
