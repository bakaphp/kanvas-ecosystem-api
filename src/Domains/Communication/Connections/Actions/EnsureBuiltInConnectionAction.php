<?php

declare(strict_types=1);

namespace Kanvas\Communication\Connections\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Connections\Enums\SetupStatusEnum;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Contracts\BuiltInCarrier;
use Kanvas\Companies\Models\Companies;

/**
 * The one connection a company has on a built-in carrier, made the first time a message needs it:
 * there is no vendor to register with, so it is ready the moment it exists. The account follows
 * the company's settings — a changed from address is the same connection, not a second one.
 */
final class EnsureBuiltInConnectionAction
{
    /**
     * @param class-string<BuiltInCarrier> $carrier
     */
    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly string $carrier
    ) {
    }

    public function execute(): ?Connection
    {
        $account = $this->carrier::account($this->app, $this->company);

        if ($account === null) {
            return null;
        }

        $connection = $this->existing();

        if ($connection === null) {
            try {
                return Connection::query()->create([
                    'apps_id' => $this->app->getId(),
                    'companies_id' => $this->company->getId(),
                    'integrations_company_id' => 0,
                    'provider' => $this->carrier::slug(),
                    'account_key' => $account->key,
                    'label' => $account->label,
                    'config' => $account->attributes ?: null,
                    'setup_status' => SetupStatusEnum::READY,
                    'health' => 'unknown',
                    'enabled' => true,
                    'activation_version' => 1,
                    'first_ready_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // Two sends raced to make it; the index picked one.
                return $this->existing();
            }
        }

        if ($connection->account_key !== $account->key) {
            $connection->forceFill(['account_key' => $account->key, 'label' => $account->label])->saveOrFail();
        }

        return $connection;
    }

    private function existing(): ?Connection
    {
        return Connection::query()
            ->fromApp($this->app)
            ->fromCompany($this->company->getId())
            ->live()
            ->where('provider', $this->carrier::slug())
            ->where('integrations_company_id', 0)
            ->orderBy('id')
            ->first();
    }
}
