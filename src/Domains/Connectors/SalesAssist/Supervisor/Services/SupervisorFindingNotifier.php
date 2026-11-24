<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Supervisor\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\SalesAssist\Supervisor\Notifications\SupervisorFindingsNotification;
use Kanvas\Users\Models\Users;

/**
 * Sends to everyone holding the AgentDeveloper role in the app. Unlike the company reports this does not
 * filter by company membership: the role is the team that maintains the agents across every dealer,
 * and most of them are not users of the dealer being reported.
 */
final class SupervisorFindingNotifier
{
    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
    ) {
    }

    /**
     * @param array<string, mixed> $finding
     */
    public function critical(array $finding): int
    {
        return $this->send("[Critical] {$this->company->name}: supervisor finding", [$finding]);
    }

    /**
     * @param list<array<string, mixed>> $findings
     */
    public function digest(array $findings): int
    {
        if ($findings === []) {
            return 0;
        }

        return $this->send("{$this->company->name}: supervisor daily summary", $findings);
    }

    /**
     * @param list<array<string, mixed>> $findings
     */
    private function send(string $subject, array $findings): int
    {
        $recipients = $this->recipients();

        if ($recipients->isEmpty()) {
            Log::warning('SalesAssist supervisor has nobody to notify; no user holds the AgentDeveloper role', [
                'app_id' => $this->app->getId(),
                'company_id' => $this->company->getId(),
            ]);

            return 0;
        }

        foreach ($recipients as $recipient) {
            NotificationFacade::send([$recipient], new SupervisorFindingsNotification($this->company, $subject, $findings));
        }

        return $recipients->count();
    }

    /**
     * @return Collection<int, Users>
     */
    private function recipients(): Collection
    {
        try {
            $role = RolesRepository::getByNameFromCompany(name: RolesEnums::AGENT_DEVELOPER->value, app: $this->app);
        } catch (ModelNotFoundException) {
            return new Collection();
        }

        return Users::query()
            ->select('users.*')
            ->join('assigned_roles', 'assigned_roles.entity_id', '=', 'users.id')
            ->where('assigned_roles.entity_type', Users::class)
            ->where('assigned_roles.scope', RolesEnums::getScope($this->app))
            ->where('assigned_roles.role_id', $role->id)
            ->where('users.is_deleted', 0)
            ->whereNotNull('users.email')
            ->where('users.email', '!=', '')
            ->distinct()
            ->get();
    }
}
