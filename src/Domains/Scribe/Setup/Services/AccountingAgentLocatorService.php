<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Setup\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Accounting\AccountsPayableAgent;
use Kanvas\Intelligence\Agents\Neuron\Accounting\AccountsReceivableAgent;

/**
 * The company's AP or AR agent: the oldest active agent with its own user whose type runs that handler.
 * Agent names differ per tenant and per environment, the handler does not. Oldest first, so a second
 * agent hired later does not take over the threads and tasks the first one started; a user is required
 * because every caller acts, writes or is tasked as that agent.
 */
class AccountingAgentLocatorService
{
    /** @var list<class-string> */
    public const array HANDLERS = [AccountsPayableAgent::class, AccountsReceivableAgent::class];

    public function payables(AppInterface $app, CompanyInterface $company): ?Agent
    {
        return $this->byHandler($app, $company, AccountsPayableAgent::class);
    }

    public function receivables(AppInterface $app, CompanyInterface $company): ?Agent
    {
        return $this->byHandler($app, $company, AccountsReceivableAgent::class);
    }

    /**
     * @return list<Agent>
     */
    public function all(AppInterface $app, CompanyInterface $company): array
    {
        return array_values(array_filter([
            $this->payables($app, $company),
            $this->receivables($app, $company),
        ]));
    }

    public static function isAccountingHandler(?string $handler): bool
    {
        return in_array($handler, self::HANDLERS, true);
    }

    private function byHandler(AppInterface $app, CompanyInterface $company, string $handler): ?Agent
    {
        /** @var Agent|null $agent */
        $agent = Agent::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('is_active', true)
            ->where('is_deleted', false)
            ->whereNotNull('user_id')
            ->whereHas('type', fn (Builder $query) => $query->where('handler', $handler))
            ->orderBy('id')
            ->first();

        return $agent;
    }
}
