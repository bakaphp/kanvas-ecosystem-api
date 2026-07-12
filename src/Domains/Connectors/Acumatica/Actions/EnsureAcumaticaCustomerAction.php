<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum;
use Kanvas\Connectors\Acumatica\Services\AcumaticaWriteService;
use Kanvas\Connectors\Acumatica\Support\AcumaticaPayload;
use Kanvas\Connectors\Acumatica\Traits\HasAcumaticaWriter;
use Kanvas\Guild\Customers\Models\People;

/**
 * The AR/sales mirror of EnsureAcumaticaVendorAction — resolves a Souk order's buyer (a Guild People)
 * to its Acumatica customer code, creating the customer in the ERP when it doesn't exist yet. Lazy
 * and push-time so a draft/abandoned order never leaves a junk customer; the code is cached back onto
 * the People, so the next order for the same buyer is a no-op.
 *
 * People (not Organization) because a Souk sales order's customer is an individual buyer. In Acumatica
 * both customers and vendors are BAccount rows, so the ERP shape matches the vendor action exactly.
 */
class EnsureAcumaticaCustomerAction
{
    use HasAcumaticaWriter;

    public function __construct(
        protected Apps $app,
        protected People $customer,
        protected ?string $taxId = null,
        protected ?string $name = null,
        protected ?string $email = null,
        ?AcumaticaWriteService $writer = null,
    ) {
        $this->writer = $writer;
    }

    public function execute(): string
    {
        $existing = (string) $this->customer->get(CustomFieldEnum::CUSTOMER_ID->value, '');

        if ($existing !== '') {
            return $existing;
        }

        $name = $this->resolveName();

        $record = $this->writer()->findOrCreate('Customer', $this->findQuery($name), $this->createBody($name));

        $code = (string) (AcumaticaPayload::value($record, 'CustomerID') ?? AcumaticaPayload::recordId($record) ?? '');

        if ($code !== '') {
            $this->customer->set(CustomFieldEnum::CUSTOMER_ID->value, $code);
        }

        return $code;
    }

    private function resolveName(): string
    {
        if ($this->name !== null && $this->name !== '') {
            return $this->name;
        }

        return trim($this->customer->firstname . ' ' . $this->customer->lastname);
    }

    /**
     * @return array<string, mixed>
     */
    private function findQuery(string $name): array
    {
        if ($this->taxId !== null && $this->taxId !== '') {
            return ['$filter' => "TaxRegistrationID eq '" . AcumaticaPayload::escapeLiteral($this->taxId) . "'", '$top' => 1];
        }

        return ['$filter' => "CustomerName eq '" . AcumaticaPayload::escapeLiteral($name) . "'", '$top' => 1];
    }

    /**
     * @return array<string, mixed>
     */
    private function createBody(string $name): array
    {
        return AcumaticaPayload::wrap([
            'CustomerName' => $name,
            'TaxRegistrationID' => $this->taxId,
            'Email' => $this->email,
        ]);
    }
}
