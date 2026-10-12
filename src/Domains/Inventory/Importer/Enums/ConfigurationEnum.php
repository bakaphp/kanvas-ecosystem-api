<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Importer\Enums;

enum ConfigurationEnum: string
{
    /**
     * Company setting, on unless set to 0: finishProductImport unpublishes what the run did not
     * send. Off, the run still closes and reports, but nothing is unpublished.
     */
    case UNPUBLISH_MISSING_ON_FINISH = 'product_import_unpublish_missing';
}
