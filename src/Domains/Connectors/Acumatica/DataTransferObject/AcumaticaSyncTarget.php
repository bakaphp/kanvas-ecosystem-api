<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\DataTransferObject;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Regions\Models\Regions;
use Kanvas\Users\Models\Users;

/**
 * A fully-resolved, sync-enabled company: everything the scheduler needs to build a pull for one
 * Acumatica legal entity. `region` is nullable — finance entities don't need it, so a company with
 * no region still syncs GL/AR/AP (only the inventory pulls are skipped).
 */
final readonly class AcumaticaSyncTarget
{
    public function __construct(
        public Apps $app,
        public Companies $company,
        public Users $user,
        public ?Regions $region,
        public int $acumaticaCompanyId,
    ) {
    }
}
