<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Neuron\Tools\Concerns;

use Illuminate\Database\Eloquent\ModelNotFoundException as EloquentModelNotFoundException;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Movipass\Enums\MovipassRolesEnum;
use Kanvas\Connectors\Movipass\Support\ProviderAdminGuard;
use Kanvas\Enums\StateEnums;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Intelligence\Agents\Neuron\Tools\Concerns\RequiresTurnConfirmation;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\MatchesNameTerms;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\Users\Models\UserCompanyApps;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;
use Nuwave\Lighthouse\Exceptions\AuthorizationException;
use Throwable;

trait ManagesProviderAdmin
{
    use GuardsAdminForTool;
    use HasKanvasContext;
    use MatchesNameTerms;
    use RequiresTurnConfirmation;

    protected const int CANDIDATE_LIMIT = 10;

    protected function refuseUnlessProviderAdmin(): ?array
    {
        if (! $this->hasTenantContext()) {
            return $this->tenantContextMissingError('manage provider users');
        }

        $refusal = ProviderAdminGuard::inAppScope(
            $this->app,
            fn (): ?array => $this->requireRequestingAdminOrError()
        );

        return $refusal === null ? null : $this->denied($refusal['message'], ['executed' => false]);
    }

    protected function actor(): Users
    {
        return $this->requestingUser;
    }

    protected function confirmingUserId(): int
    {
        return $this->actor()->getId();
    }

    protected function recordAudit(string $action, array $payload): void
    {
        try {
            new AppendEventAction(
                new EventData(
                    app: $this->app,
                    company: $this->company,
                    sourceDomain: 'Movipass',
                    eventType: 'movipass.provider_admin.' . $action,
                    status: EventStatusEnum::SUCCESS,
                    sourceEntityType: Users::class,
                    sourceEntityId: $this->actor()->getId(),
                    actorType: 'User',
                    actorId: $this->actor()->getId(),
                    payload: [
                        ...$payload,
                        'agent_id' => $this->contextAgent()?->getId(),
                    ],
                ),
            )->execute();
        } catch (Throwable $e) {
            report($e);
        }
    }

    protected function appCompanyIds(): array
    {
        return UserCompanyApps::query()
            ->where('apps_id', $this->app->getId())
            ->where('is_deleted', StateEnums::NO->getValue())
            ->pluck('companies_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    protected function findCompanyInApp(int $companyId): ?Companies
    {
        if (! in_array($companyId, $this->appCompanyIds(), true)) {
            return null;
        }

        return Companies::query()->whereKey($companyId)->first();
    }

    protected function findUserInApp(int $userId): ?Users
    {
        $belongs = UsersAssociatedApps::query()
            ->where('apps_id', $this->app->getId())
            ->where('users_id', $userId)
            ->where('is_deleted', StateEnums::NO->getValue())
            ->exists();

        return $belongs ? Users::query()->whereKey($userId)->first() : null;
    }

    protected function parseRole(string $role): ?MovipassRolesEnum
    {
        foreach (MovipassRolesEnum::cases() as $case) {
            if (strcasecmp($case->value, trim($role)) === 0) {
                return $case;
            }
        }

        return null;
    }

    protected function resolveGrantableRole(string $role): MovipassRolesEnum|array
    {
        $parsed = $this->parseRole($role);

        if ($parsed === null) {
            return $this->invalidArgs(
                "\"{$role}\" is not a MoviPass role.",
                ['executed' => false, 'valid_roles' => array_column(MovipassRolesEnum::cases(), 'value')],
                'Call list_provider_roles and use one of its role values exactly.'
            );
        }

        try {
            ProviderAdminGuard::inAppScope($this->app, function () use ($parsed): void {
                ProviderAdminGuard::assertMayHandOut($this->actor(), $parsed, $this->app);
                ProviderAdminGuard::roleFor($parsed, $this->app);
            });
        } catch (AuthorizationException $e) {
            return $this->denied($e->getMessage(), ['executed' => false]);
        } catch (EloquentModelNotFoundException) {
            return $this->failed(
                "The role {$parsed->value} does not exist in this app yet.",
                ['executed' => false],
                'An administrator must run kanvas:movipass-setup-roles for this app.'
            );
        }

        return $parsed;
    }

    protected function refuseUnlessMayManageTarget(
        Users $target,
        Companies $company,
        MovipassRolesEnum $role,
    ): ?array {
        try {
            ProviderAdminGuard::inAppScope($this->app, function () use ($target, $company, $role): void {
                ProviderAdminGuard::assertCompanyInApp($company, $this->app);
                ProviderAdminGuard::assertMayManageTarget($this->actor(), $target, $role, $this->app);
            });
        } catch (AuthorizationException $e) {
            return $this->denied($e->getMessage(), ['executed' => false]);
        } catch (ModelNotFoundException|EloquentModelNotFoundException $e) {
            return $this->notFound(['executed' => false, 'message' => $e->getMessage()]);
        }

        return null;
    }
}
