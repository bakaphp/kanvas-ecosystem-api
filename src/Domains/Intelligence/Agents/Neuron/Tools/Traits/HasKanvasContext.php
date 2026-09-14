<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Users\Models\Users;

trait HasKanvasContext
{
    protected Apps $app;
    protected Companies $company;
    protected Users $user;

    /**
     * The agent running this turn, when the host knows it.
     *
     * Nullable and last, so the ~90 existing `withContext($app, $company, $user)` call sites keep
     * working. It cannot be recovered from `$user`: an agent's user is routinely shared — one user in
     * production backs 28 agents — so `Agent::fromUser()` returns an arbitrary one of them.
     *
     * NOT named `$agent`: four tools already promote their own `$agent` in the constructor
     * (ScheduleReminderTool, ScheduleAgentTaskTool, CreateEngagementPageTool,
     * ProvisionMyEmailInboxTool) and a trait re-declaring it is a fatal property conflict at class
     * load, not a runtime error.
     */
    protected ?Agent $actingAgent = null;

    public function withContext(
        Apps $app,
        Companies $company,
        Users $user,
        ?Agent $agent = null,
    ): static {
        $this->app = $app;
        $this->company = $company;
        $this->user = $user;
        $this->actingAgent = $agent;

        return $this;
    }

    /**
     * The agent acting this turn, or null when the host could not name one.
     */
    protected function contextAgent(): ?Agent
    {
        return $this->actingAgent;
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

    /**
     * The refusal a tool returns when it was wired without a tenant. Ids reaching a tool are
     * LLM-supplied and therefore prompt-injectable, so an unscoped fallback would resolve records
     * belonging to another company — every tool fails closed with the same shape instead, and the
     * miswiring is reported rather than swallowed.
     *
     * @return array{reason: string, message: string}
     */
    protected function tenantContextMissingError(string $subject): array
    {
        report(new ValidationException(
            static::class . ' ran with no tenant context. Register the tool through '
            . 'mergeRegisteredTools()/addToolContext(), or call withContext($app, $company, $user) on it.'
        ));

        return [
            'reason' => 'no_tenant_context',
            'message' => "This tool is not bound to a company right now, so the {$subject} cannot be handled.",
        ];
    }
}
