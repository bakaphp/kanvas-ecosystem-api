<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\DailyLearning\Actions;

use Kanvas\AccessControlList\Actions\CreateRoleAction;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Silber\Bouncer\Database\Role as SilberRole;

/**
 * Bootstrap the AgentReport Bouncer role on a given app. Uses the canonical
 * CreateRoleAction so the (Bouncer::scope()->to(...) + firstOrCreate) sequence
 * is identical to manual role creation through the GraphQL mutation — the
 * earlier hand-rolled firstOrCreate inserted with a stale scope when iterated
 * across apps because Bouncer's static scope state leaked from the previous
 * app's run.
 *
 * Idempotent: CreateRoleAction's validator throws `name has already been
 * taken` when the (name, scope) pair already exists. We treat that as success
 * and fetch the existing role.
 */
class EnsureAgentReportRoleAction
{
    public function __construct(protected readonly Apps $app)
    {
    }

    public function execute(): SilberRole
    {
        try {
            return new CreateRoleAction(
                name: RolesEnums::AGENT_REPORT->value,
                title: 'Agent Report Recipient',
                app: $this->app,
            )->execute();
        } catch (ValidationException $e) {
            // Idempotent re-run: role already exists for this (name, scope).
            // Any other validation error genuinely belongs upstream.
            if (! str_contains($e->getMessage(), 'has already been taken')) {
                throw $e;
            }

            return RolesRepository::getByNameFromApp(
                name: RolesEnums::AGENT_REPORT->value,
                app: $this->app,
            );
        }
    }
}
