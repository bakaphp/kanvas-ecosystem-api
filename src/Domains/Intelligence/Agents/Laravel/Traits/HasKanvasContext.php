<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Traits;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Users\Models\Users;

trait HasKanvasContext
{
    protected Apps $app;
    protected Companies $company;
    protected ?Agent $agent = null;

    public function withContext(
        Apps $app,
        Companies $company,
        ?Agent $agent = null
    ): static {
        $this->app = $app;
        $this->company = $company;
        $this->agent = $agent;

        return $this;
    }

    /**
     * The acting user for records a tool writes. The agent's own user comes first: it is the
     * semantically correct actor and can't drift from the tenant, unlike whoever happens to be
     * authenticated on the request that woke the agent. Mirrors the Neuron trait's contextUser().
     */
    protected function contextUser(): ?Users
    {
        /** @var Users|null $user */
        $user = $this->agent?->user ?? auth()->user();

        return $user;
    }
}
