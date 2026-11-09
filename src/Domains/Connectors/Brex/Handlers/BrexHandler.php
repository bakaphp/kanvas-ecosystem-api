<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Brex\Handlers;

use Kanvas\Connectors\Brex\DataTransferObject\Brex as BrexDto;
use Kanvas\Connectors\Brex\Enums\ConfigurationEnum;
use Kanvas\Connectors\Brex\Services\BrexAccountService;
use Kanvas\Connectors\Brex\Services\BrexService;
use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\Ledger\Services\GlOwnershipService;
use Override;
use Throwable;

/**
 * Invoked by the generic `integrationCompany` mutation. Read-only: the connector pulls the bank feed and never
 * moves money, so a token with the `accounts.*.readonly` and `transactions.*.readonly` scopes is enough.
 */
class BrexHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $dto = BrexDto::viaRequest($this->data, $this->app, $this->company);

        if ($dto->apiToken === '') {
            throw new ValidationException('Brex API token is required.');
        }

        // An ERP-owned company already books its own cash; a second feed would double-count it.
        $erp = new GlOwnershipService()->externalGlSource($this->company);
        if ($erp !== null) {
            throw new ValidationException(
                "This company's general ledger is owned by '{$erp->documentSource()}', which already imports "
                . 'its own cash transactions. Connecting Brex as well would double-count cash.'
            );
        }

        $previousToken = (string) $this->company->get(ConfigurationEnum::API_TOKEN->value);
        BrexService::setup($dto);

        try {
            new BrexAccountService($this->app, $this->company)->cashAccounts();
        } catch (Throwable $e) {
            $this->restoreAfterFailedSetup($previousToken);

            throw new ValidationException('Brex authentication failed: ' . $e->getMessage());
        }

        return true;
    }

    /**
     * A mistyped token while rotating must not disconnect a company whose current token works.
     */
    private function restoreAfterFailedSetup(string $previousToken): void
    {
        if ($previousToken === '') {
            $this->teardown();

            return;
        }

        $this->company->setEncrypted(ConfigurationEnum::API_TOKEN->value, $previousToken);
    }

    #[Override]
    protected function companySettingKeys(): array
    {
        return [
            ConfigurationEnum::API_TOKEN->value,
            ConfigurationEnum::SYNC_ENABLED->value,
            ConfigurationEnum::SYNC_APP_ID->value,
        ];
    }
}
