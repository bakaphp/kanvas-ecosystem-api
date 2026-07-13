<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\Mercury\DataTransferObject\Mercury as MercuryDto;
use Kanvas\Connectors\Mercury\Services\MercuryAccountService;
use Kanvas\Connectors\Mercury\Services\MercuryService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\Ledger\Services\GlOwnershipService;
use Override;
use Throwable;

/**
 * Invoked by the generic `integrationCompany` mutation — Mercury needs no bespoke GraphQL, because its
 * setup is exactly "take a token, prove it works".
 */
class MercuryHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $dto = MercuryDto::viaRequest($this->data, $this->app, $this->company);

        if ($dto->apiToken === '') {
            throw new ValidationException('Mercury API token is required.');
        }

        // Refuse the connection outright rather than let a tenant wire up a feed that will never be allowed
        // to post. An ERP-owned company already books its own cash; a second feed would double-count it.
        $erp = new GlOwnershipService()->externalGlSource($this->company);
        if ($erp !== null) {
            throw new ValidationException(
                "This company's general ledger is owned by '{$erp->documentSource()}', which already imports "
                . 'its own cash transactions. Connecting Mercury as well would double-count cash. Link the '
                . 'bank account inside that ERP instead.'
            );
        }

        MercuryService::setup($dto);

        try {
            new MercuryAccountService($this->app, $this->company)->list();
        } catch (Throwable $e) {
            throw new ValidationException('Mercury authentication failed: ' . $e->getMessage());
        }

        return true;
    }
}
