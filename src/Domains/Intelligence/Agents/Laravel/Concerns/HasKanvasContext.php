<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Concerns;

use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
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
     * Prompts name tools by the snake form of the #[AgentTool] label (`create_lead`); without name() laravel-ai
     * declares the class basename, which no prompt uses. A tool the prompts call something else overrides this.
     */
    public function name(): string
    {
        return Str::slug(AgentTool::fromClass($this)?->name ?? Str::beforeLast(class_basename($this), 'Tool'), '_');
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
