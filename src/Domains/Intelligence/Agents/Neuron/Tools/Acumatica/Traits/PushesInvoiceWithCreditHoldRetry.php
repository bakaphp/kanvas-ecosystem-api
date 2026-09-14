<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica\Traits;

use Kanvas\Connectors\Acumatica\Actions\PushInvoiceToAcumaticaAction;
use Kanvas\Connectors\Acumatica\Exceptions\AcumaticaWriteException;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Throwable;

/** Shared push-with-retry for AR invoice tools — intermittent "Release button is disabled" on this tenant's Credit Hold check. */
trait PushesInvoiceWithCreditHoldRetry
{
    private function pushInvoiceWithCreditHoldRetry(Invoice $invoice): string
    {
        return $this->retryOnReleaseDisabled(
            fn (): string => new PushInvoiceToAcumaticaAction($invoice)->execute(),
        );
    }

    private function retryOnReleaseDisabled(callable $push, int $maxAttempts = 3): string
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return $push();
            } catch (AcumaticaWriteException|Throwable $e) {
                $lastException = $e;

                if (! str_contains($e->getMessage(), 'Release button is disabled') || $attempt === $maxAttempts) {
                    throw $e;
                }

                sleep(3);
            }
        }

        throw $lastException;
    }
}
