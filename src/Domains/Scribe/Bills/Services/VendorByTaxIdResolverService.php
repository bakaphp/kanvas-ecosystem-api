<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Bills\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Guild\Organizations\Actions\ResolveOrCreateOrganizationFromVendorPayloadAction;
use Kanvas\Guild\Organizations\Enums\TaxIdTypeEnum;
use Kanvas\Guild\Organizations\Models\Organization;

/**
 * A vendor by its tax id (`organizations.tax_id`), the legal identity a name match can't be trusted for.
 */
class VendorByTaxIdResolverService
{
    public function find(AppInterface $app, CompanyInterface $company, ?string $taxId): ?Organization
    {
        return Organization::findByTaxId($app, $company, $taxId);
    }

    /**
     * Tax id first, then name/email through the inbox's resolver. A name match carrying a different tax id
     * is a different company, so it comes back null rather than booking the bill against the wrong vendor.
     * `$taxIdType` is for a caller that knows the id's kind regardless of the company's country (a received
     * e-CF is Dominican); without it the type is inferred on save.
     *
     * @param array<string, mixed> $payload
     */
    public function resolveOrCreate(
        AppInterface $app,
        CompanyInterface $company,
        UserInterface $user,
        ?string $taxId,
        array $payload,
        ?TaxIdTypeEnum $taxIdType = null
    ): ?Organization {
        $taxId = Str::normalizeTaxId($taxId);
        $byTaxId = $this->find($app, $company, $taxId);
        if ($byTaxId !== null) {
            return $byTaxId;
        }

        $vendor = new ResolveOrCreateOrganizationFromVendorPayloadAction(
            app: $app,
            company: $company,
            user: $user,
            payload: [...$payload, 'vendor_tax_id_type' => $taxIdType],
        )->execute();

        if ($vendor === null || $taxId === null) {
            return $vendor;
        }

        if ($vendor->tax_id === null) {
            $vendor->tax_id = $taxId;
            $vendor->tax_id_type = $taxIdType;
            $vendor->saveOrFail();

            return $vendor;
        }

        return $vendor->tax_id === $taxId ? $vendor : null;
    }
}
