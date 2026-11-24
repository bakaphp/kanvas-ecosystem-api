<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Orchestrator\Routing\Actions;

use Baka\Validations\EmailDomain;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\HumanResources\Employees\Services\EmployeeIdentityResolver;
use Kanvas\NervousSystem\Orchestrator\Signals\DataTransferObject\InboundSignal;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;

/**
 * The attendees from outside the company. Staff attend nearly every meeting, so only outsiders say
 * which customer it was with. Staff are the HR employees; a company with no HR yet falls back to its
 * users. Their non-free-mail domains count as internal too, which covers a colleague HR doesn't list.
 */
class ResolveExternalAttendeesAction
{
    /**
     * @param list<string> $extraInternalDomains aliases HR can't show (an old domain)
     */
    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly array $extraInternalDomains = []
    ) {
    }

    /**
     * @return list<string> lower-cased emails
     */
    public function execute(InboundSignal $signal): array
    {
        $attendees = $signal->actorEmails();
        if ($attendees === []) {
            return [];
        }

        $internal = new EmployeeIdentityResolver()->emails($this->company, $this->app);
        if ($internal === []) {
            $internal = $this->companyUserEmails();
        }

        $internalEmails = array_flip($internal);
        $internalDomains = array_flip(array_filter(array_map(
            EmailDomain::companyDomain(...),
            [...$internal, ...$this->extraInternalDomains]
        )));

        return array_values(array_filter(
            $attendees,
            static fn (string $email): bool => ! isset($internalEmails[$email])
                && ! isset($internalDomains[EmailDomain::companyDomain($email) ?? ''])
        ));
    }

    /**
     * @return list<string>
     */
    private function companyUserEmails(): array
    {
        $userIds = UsersAssociatedApps::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('is_deleted', 0)
            ->pluck('users_id')
            ->all();

        if ($userIds === []) {
            return [];
        }

        return Users::query()
            ->whereIn('id', $userIds)
            ->pluck('email')
            ->map(static fn (mixed $email): string => strtolower(trim((string) $email)))
            ->filter()
            ->values()
            ->all();
    }
}
