<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Alanube\Actions;

use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Alanube\Client;
use Kanvas\Exceptions\ValidationException;

/**
 * Accepts or rejects a vendor's e-CF (`POST /received-documents/{id}/commercial-response`); Alanube forwards it
 * to the vendor and DGII. The received document is found by vendor RNC + e-NCF — the pair the bill is keyed
 * on — so nothing Alanube-specific has to be stored on the bill.
 */
class SendAlanubeCommercialResponseAction
{
    // Alanube documents no limit for notAcceptedDetail; capped defensively until verified in sandbox.
    private const int REASON_MAX = 250;

    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly string $vendorTaxId,
        private readonly string $encf,
        private readonly bool $accepted,
        private readonly ?string $rejectionReason = null,
    ) {
    }

    /**
     * @return string the commercial approval id Alanube created
     */
    public function execute(): string
    {
        if (! $this->accepted && trim((string) $this->rejectionReason) === '') {
            throw new ValidationException('Rejecting a vendor e-CF needs a reason; DGII sends it to the vendor.');
        }

        $client = new Client($this->app, $this->company);
        $documentId = $this->receivedDocumentId($client);

        $body = ['commercialResponse' => $this->accepted ? 'ACCEPTED' : 'REJECTED'];

        if (! $this->accepted) {
            $body['notAcceptedDetail'] = mb_substr(trim((string) $this->rejectionReason), 0, self::REASON_MAX);
        }

        $response = $client->post($client->scopedPath('received-documents/' . $documentId . '/commercial-response'), $body);

        return (string) ($response['commercialApprovalInfo']['id'] ?? '');
    }

    private function receivedDocumentId(Client $client): string
    {
        $response = $client->get(
            $client->scopedPath('received-documents'),
            ['rnc' => Str::digitsOnly($this->vendorTaxId), 'documentNumber' => $this->encf],
        ) ?? [];

        $documents = is_array($response['documents'] ?? null) ? $response['documents'] : $response;

        foreach ($documents as $document) {
            if (is_array($document) && ($document['documentNumber'] ?? null) === $this->encf && ! empty($document['id'])) {
                return (string) $document['id'];
            }
        }

        throw new ValidationException("Alanube has no received e-CF {$this->encf} from {$this->vendorTaxId}.");
    }
}
