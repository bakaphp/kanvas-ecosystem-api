<?php

declare(strict_types=1);

namespace Kanvas\Communication\Contracts;

use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Connections\DataTransferObject\AccountSelection;
use Kanvas\Companies\Models\Companies;

/**
 * A carrier with no integration behind it: there is no vendor to register with, no webhook and
 * nothing to tear down. Its one connection per company is made the first time a message needs it,
 * and it only carries a channel the company has no default connection for.
 */
interface BuiltInCarrier extends CommunicationCarrier
{
    /**
     * @return AccountSelection|null null when the company cannot send through it
     */
    public static function account(Apps $app, Companies $company): ?AccountSelection;
}
