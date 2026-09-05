<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Bills\Observers;

use Kanvas\Scribe\Bills\Models\Bill;

class BillObserver
{
    public function updating(Bill $bill): void
    {
        $bill->clearLightHouseCache(withKanvasConfiguration: false);
    }
}
