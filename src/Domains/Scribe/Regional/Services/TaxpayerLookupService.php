<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Regional\Services;

use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Scribe\ElectronicInvoicing\Services\EcfProviderResolver;
use Kanvas\Scribe\ElectronicInvoicing\Services\EcfTypeResolver;
use Kanvas\Scribe\Regional\DataTransferObject\TaxpayerLookup;
use Throwable;

/**
 * A Dominican RNC / cédula checked at DGII through whichever e-CF provider the company uses. A lookup
 * that fails is reported as failed, never as "not registered": an outage must not mark a vendor invalid.
 */
class TaxpayerLookupService
{
    public function lookup(Apps $app, Companies $company, string $rawTaxId): TaxpayerLookup
    {
        $taxId = Str::digitsOnly($rawTaxId);

        if (! EcfTypeResolver::isDominicanTaxId($taxId)) {
            return new TaxpayerLookup(TaxpayerLookup::INVALID_FORMAT, $taxId);
        }

        $resolver = new EcfProviderResolver();
        if (! $resolver->issuesEcf($company)) {
            return new TaxpayerLookup(TaxpayerLookup::NO_PROVIDER, $taxId);
        }

        try {
            $legalName = $resolver->resolve($app, $company)->lookupTaxpayer($taxId);
        } catch (Throwable $e) {
            report($e);

            return new TaxpayerLookup(TaxpayerLookup::FAILED, $taxId);
        }

        return $legalName === null
            ? new TaxpayerLookup(TaxpayerLookup::NOT_REGISTERED, $taxId)
            : new TaxpayerLookup(TaxpayerLookup::FOUND, $taxId, $legalName);
    }
}
