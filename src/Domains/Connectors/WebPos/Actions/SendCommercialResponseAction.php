<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WebPos\Actions;

use Kanvas\Connectors\WebPos\Client;
use Kanvas\Connectors\WebPos\Enums\CommercialResponseEnum;
use Kanvas\Connectors\WebPos\Enums\HostEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\ElectronicInvoicing\Support\ReceivedEcfCompliance;

/**
 * Commercial approval (2) or rejection (4) of a vendor's e-CF. WebPOS forwards it to DGII and to the vendor.
 * Only bills that came in as a received e-CF have an e-NCF to answer for.
 */
class SendCommercialResponseAction
{
    public function __construct(
        public readonly Bill $bill,
        public readonly CommercialResponseEnum $response,
        public readonly ?string $reason = null,
        private ?Client $client = null,
    ) {
    }

    /**
     * @return array<array-key, mixed>
     */
    public function execute(): array
    {
        if (! ReceivedEcfCompliance::isReceivedEcf($this->bill)) {
            throw new ValidationException("Bill {$this->bill->getId()} did not come in as a received e-CF; there is nothing to answer.");
        }

        $vendorRnc = (string) ReceivedEcfCompliance::vendorTaxId($this->bill);
        $encf = (string) ReceivedEcfCompliance::encf($this->bill);

        $reason = trim((string) $this->reason);

        if ($this->response === CommercialResponseEnum::REJECT && $reason === '') {
            throw new ValidationException('Rejecting a vendor e-CF requires a reason; DGII and the vendor receive it.');
        }

        $client = $this->client ?? new Client($this->bill->company);

        $result = $client->post(HostEnum::RECEPTION, 'api/fedo/ak/v1/sendRecEvent/' . $client->companyLicense . '/' . Client::API_KEY, array_filter([
            'vendorRNC' => $vendorRnc,
            'eCF' => $encf,
            'event' => $this->response->value,
            'motivoRechazo' => $this->response === CommercialResponseEnum::REJECT ? $reason : null,
        ], fn ($value): bool => $value !== null));

        ReceivedEcfCompliance::recordCommercialResponse($this->bill, [
            'event' => $this->response->value,
            'reason' => $reason !== '' ? $reason : null,
            'dgi_status' => $result['dgiSts'] ?? null,
            'vendor_status' => $result['vendorSts'] ?? null,
        ]);

        return $result;
    }
}
