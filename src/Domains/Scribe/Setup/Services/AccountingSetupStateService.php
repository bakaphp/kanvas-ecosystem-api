<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Setup\Services;

use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\Setup\Enums\AccountingSetupConfigurationEnum;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;

/**
 * The company settings the accounting setup reads back: its per-step state and the default approver.
 */
class AccountingSetupStateService
{
    /**
     * @return array{ran_at?: string, country?: ?string, steps?: array<string, array<string, mixed>>}
     */
    public function state(Companies $company): array
    {
        $state = $company->get(AccountingSetupConfigurationEnum::SETUP_STATE->value);

        return is_array($state) ? $state : [];
    }

    public function save(Companies $company, array $state): void
    {
        $company->set(AccountingSetupConfigurationEnum::SETUP_STATE->value, $state);
    }

    public function hasRun(Companies $company): bool
    {
        return isset($this->state($company)['ran_at']);
    }

    public function defaultApprover(Companies $company): ?Users
    {
        $userId = (int) $company->get(AccountingSetupConfigurationEnum::DEFAULT_APPROVER->value);
        if ($userId <= 0) {
            return null;
        }

        return $this->activeUser($userId);
    }

    /**
     * Who signs and is asked when nobody else is named: the default approver, else the company owner.
     */
    public function approverOrOwner(Companies $company): Users
    {
        return $this->defaultApprover($company) ?? $company->user;
    }

    /**
     * The approver signs company money, so it has to be someone in this company.
     *
     * @throws ValidationException
     */
    public function memberApprover(Companies $company, int $userId): Users
    {
        $user = $this->activeUser($userId)
            ?? throw new ValidationException('The approver must be a user of this company.');

        try {
            UsersRepository::belongsToCompany($user, $company);
        } catch (ModelNotFoundException) {
            throw new ValidationException('The approver must be a user of this company.');
        }

        return $user;
    }

    public function setDefaultApprover(Companies $company, Users $user): void
    {
        $company->set(AccountingSetupConfigurationEnum::DEFAULT_APPROVER->value, $user->getId());
    }

    private function activeUser(int $userId): ?Users
    {
        /** @var Users|null $user */
        $user = Users::query()->whereKey($userId)->where('is_deleted', 0)->first();

        return $user;
    }
}
