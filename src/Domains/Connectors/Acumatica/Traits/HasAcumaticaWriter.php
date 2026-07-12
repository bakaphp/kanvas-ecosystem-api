<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Traits;

use Kanvas\Connectors\Acumatica\Services\AcumaticaWriteService;

/**
 * Lazily builds the gated write service from the action's app. The using action takes an optional
 * `?AcumaticaWriteService $writer` in its constructor (assigned to `$this->writer`) so tests can
 * inject a mock, and must expose `$this->app`.
 */
trait HasAcumaticaWriter
{
    protected ?AcumaticaWriteService $writer = null;

    private function writer(): AcumaticaWriteService
    {
        return $this->writer ??= new AcumaticaWriteService($this->app);
    }
}
