<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum;
use Kanvas\Connectors\Acumatica\Services\AcumaticaWriteService;
use Kanvas\Connectors\Acumatica\Support\AcumaticaPayload;
use Kanvas\Connectors\Acumatica\Traits\HasAcumaticaWriter;
use Kanvas\Guild\Organizations\Models\Organization;

/**
 * Resolves a Guild vendor org to its Acumatica vendor code, creating the vendor in Acumatica when it
 * doesn't exist yet — the lazy, push-time half of the Kanvas-first flow. Runs only when we're about
 * to push an approved bill, so a rejected bill never leaves a junk vendor in the ERP. The code is
 * cached back onto the org, so the next bill for the same vendor is a no-op.
 *
 * Match order: tax id (exact) → name. Both find (GET) and create (PUT) run in the write service's
 * gated session, so a write-disabled tenant can never reach the create branch.
 */
class EnsureAcumaticaVendorAction
{
    use HasAcumaticaWriter;

    /** The vendor org's own app — the tenant whose Acumatica config/credentials this runs against. */
    protected Apps $app;

    public function __construct(
        protected Organization $vendor,
        protected ?string $taxId = null,
        protected ?string $name = null,
        protected ?string $email = null,
        ?AcumaticaWriteService $writer = null,
    ) {
        $this->app = $vendor->app;
        $this->writer = $writer;
    }

    public function execute(): string
    {
        $existing = (string) $this->vendor->get(CustomFieldEnum::VENDOR_ID->value, '');

        if ($existing !== '') {
            return $existing;
        }

        $name = $this->name !== null && $this->name !== '' ? $this->name : (string) $this->vendor->name;

        $record = $this->writer()->findOrCreate('Vendor', $this->findQuery($name), $this->createBody($name));

        $code = (string) (AcumaticaPayload::value($record, 'VendorID') ?? AcumaticaPayload::recordId($record) ?? '');

        if ($code !== '') {
            $this->vendor->set(CustomFieldEnum::VENDOR_ID->value, $code);
        }

        return $code;
    }

    /**
     * @return array<string, mixed>
     */
    private function findQuery(string $name): array
    {
        if ($this->taxId !== null && $this->taxId !== '') {
            return ['$filter' => "TaxRegistrationID eq '" . AcumaticaPayload::escapeLiteral($this->taxId) . "'", '$top' => 1];
        }

        return ['$filter' => "VendorName eq '" . AcumaticaPayload::escapeLiteral($name) . "'", '$top' => 1];
    }

    /**
     * @return array<string, mixed>
     */
    private function createBody(string $name): array
    {
        return AcumaticaPayload::wrap([
            'VendorName' => $name,
            'TaxRegistrationID' => $this->taxId,
            'Email' => $this->email,
        ]);
    }
}
