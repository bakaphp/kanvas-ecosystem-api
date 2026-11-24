<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Alanube;

use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Alanube\Actions\SendAlanubeCommercialResponseAction;
use Kanvas\Connectors\Alanube\Enums\ConfigurationEnum;
use Kanvas\Connectors\Alanube\Enums\DocumentStatusEnum;
use Kanvas\Connectors\Alanube\Exceptions\AlanubeRequestException;
use Kanvas\Connectors\Alanube\Mappers\AlanubeDocumentMapper;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\EcfProviderInterface;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\FiscalPayloadInterface;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\RespondsToReceivedEcfInterface;
use Kanvas\Scribe\ElectronicInvoicing\DataTransferObject\EcfSubmission;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfEnvironmentEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfStatusEnum;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DataTransferObject\EcfDocument;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DrFiscalRegime;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfTypeEnum;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfDocumentLookup;
use Kanvas\Scribe\ElectronicInvoicing\Support\ReceivedEcfCompliance;
use Override;

/**
 * Every type is treated as asynchronous; an E32 under DOP 250k simply comes back already FINISHED in the
 * submit response, and the final status otherwise lands through the emission webhook or `fetchResult`.
 *
 * Alanube identifies a document by its own id, which the submission returns as providerReference and Scribe
 * stores on the attempt. A repeated submit of an attempt that already has one is answered from Alanube
 * instead of being sent twice. A 400/422 never reached DGII (reachedAuthority false), and
 * `sequenceConsumed` is DGII's secuenciaUtilizada passed through.
 */
class AlanubeEcfProvider implements EcfProviderInterface, RespondsToReceivedEcfInterface
{
    public static function country(): string
    {
        return DrFiscalRegime::COUNTRY;
    }

    // Validation answers: the e-CF never reached DGII and the e-NCF was not consumed, so Scribe can fix and resend.
    private const array VALIDATION_STATUSES = [400, 422];

    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly ?EcfEnvironmentEnum $environment = null,
    ) {
    }

    #[Override]
    public function submit(FiscalPayloadInterface $payload): EcfSubmission
    {
        $document = EcfDocument::narrow($payload);

        $existingReference = $this->providerReferenceFor($document);

        if ($existingReference !== null) {
            return $this->fetchSubmission(
                $document->type,
                $existingReference,
                $document->encf,
                wasDuplicated: true
            );
        }

        $client = $this->client();

        try {
            $response = $client->post(AlanubeDocumentMapper::endpointFor($document->type), $this->requestPayload($document));
        } catch (AlanubeRequestException $e) {
            if (! in_array($e->status, self::VALIDATION_STATUSES, true)) {
                throw $e;
            }

            return new EcfSubmission(
                status: EcfStatusEnum::REJECTED,
                encf: $document->encf,
                messages: $e->alanubeCode !== null
                    ? array_map(fn (string $message): string => "{$e->alanubeCode}: {$message}", $e->messages)
                    : $e->messages,
                reachedAuthority: false,
                rawResponse: ['status' => $e->status, 'code' => $e->alanubeCode, 'messages' => $e->messages],
            );
        }

        return self::toSubmission($response, $document->encf);
    }

    #[Override]
    public function requestPayload(FiscalPayloadInterface $payload): array
    {
        $document = EcfDocument::narrow($payload);

        return $this->client()->withCompany(new AlanubeDocumentMapper($this->senderProfile())->map($document));
    }

    #[Override]
    public function fetchResult(FiscalPayloadInterface $payload): EcfSubmission
    {
        $document = EcfDocument::narrow($payload);

        $reference = $this->providerReferenceFor($document);

        if ($reference === null) {
            return new EcfSubmission(
                status: EcfStatusEnum::WAITING,
                encf: $document->encf,
                messages: ['No Alanube document id is recorded for this e-CF yet.'],
            );
        }

        return $this->fetchSubmission($document->type, $reference, $document->encf);
    }

    public function fetchSubmission(
        EcfTypeEnum $type,
        string $alanubeId,
        string $encf,
        bool $wasDuplicated = false
    ): EcfSubmission {
        $client = $this->client();
        $response = $client->get($client->scopedPath(AlanubeDocumentMapper::endpointFor($type) . '/' . $alanubeId));

        if ($response === null) {
            return new EcfSubmission(
                status: EcfStatusEnum::WAITING,
                encf: $encf,
                providerReference: $alanubeId,
                messages: ["Alanube has no {$type->series()} document {$alanubeId}."],
            );
        }

        return self::toSubmission($response, $encf, $wasDuplicated);
    }

    #[Override]
    public function lookupTaxpayer(string $taxId): ?string
    {
        $rnc = Str::digitsOnly($taxId);

        if ($rnc === '') {
            return null;
        }

        $client = $this->client();
        $entries = $client->get($client->scopedPath('check-directory'), ['rnc' => $rnc]) ?? [];

        foreach ($entries as $entry) {
            if (is_array($entry) && (string) ($entry['rnc'] ?? '') === $rnc) {
                return Str::trimToNull((string) ($entry['nombre'] ?? ''));
            }
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $response a document as Alanube returns it from POST, GET or the emission webhook
     */
    public static function toSubmission(array $response, string $encf, bool $wasDuplicated = false): EcfSubmission
    {
        $messages = [];

        foreach ((array) ($response['governmentResponse']['value'] ?? []) as $item) {
            if (is_array($item) && ($item['valor'] ?? '') !== '') {
                $messages[] = trim(($item['codigo'] ?? '') . ' ' . $item['valor']);
            }
        }

        // Synchronous E32 that hit a DGII timeout: Alanube queued it and says so here.
        foreach ((array) ($response['response'] ?? []) as $item) {
            if (is_array($item) && ($item['message'] ?? '') !== '') {
                $messages[] = trim(($item['code'] ?? '') . ' ' . $item['message']);
            }
        }

        return new EcfSubmission(
            status: DocumentStatusEnum::toCanonical(
                isset($response['status']) ? (string) $response['status'] : null,
                isset($response['legalStatus']) ? (string) $response['legalStatus'] : null,
            ),
            encf: (string) ($response['documentNumber'] ?? $response['encf'] ?? $encf),
            providerReference: isset($response['id']) ? (string) $response['id'] : null,
            securityCode: isset($response['securityCode']) ? (string) $response['securityCode'] : null,
            signedAt: isset($response['signatureDate']) ? (string) $response['signatureDate'] : null,
            qrUrl: isset($response['documentStampUrl']) ? (string) $response['documentStampUrl'] : null,
            messages: $messages,
            wasDuplicated: $wasDuplicated,
            numberConsumed: isset($response['sequenceConsumed']) ? filter_var($response['sequenceConsumed'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) : null,
            rawResponse: $response,
        );
    }

    /**
     * Alanube's id for exactly the attempt the systemRef names. A new attempt has none yet, so a resend after a
     * reusable rejection is posted instead of being answered with the rejected document. A systemRef that is a
     * fiscal row's own uuid (a row sent before attempts had one) falls back to that row's latest id.
     */
    private function providerReferenceFor(EcfDocument $document): ?string
    {
        $attempt = EcfDocumentLookup::attemptBySystemRef($this->app, $this->company, $document->systemRef);

        $reference = $attempt !== null
            ? $attempt->provider_ref
            : EcfDocumentLookup::bySystemRef($this->app, $this->company, $document->systemRef)
                ?->submissions()->whereNotNull('provider_ref')->latest('attempt')->value('provider_ref');

        return Str::trimToNull(is_string($reference) ? $reference : null);
    }

    /**
     * @return array<string, mixed>
     */
    private function senderProfile(): array
    {
        $profile = $this->company->get(ConfigurationEnum::SENDER_PROFILE->value);

        if (! is_array($profile) || empty($profile['rnc'])) {
            throw new ValidationException(
                'Alanube sender profile (RNC, legal name, address) is not configured for company '
                . $this->company->getId() . '. Run kanvas:alanube-setup.'
            );
        }

        return $profile;
    }

    #[Override]
    public function sendCommercialResponse(Bill $bill, bool $accepted, ?string $reason = null): ?string
    {
        if (! ReceivedEcfCompliance::isReceivedEcf($bill)) {
            throw new ValidationException("Bill {$bill->getId()} did not come in as a received e-CF; there is nothing to answer.");
        }

        $approvalId = new SendAlanubeCommercialResponseAction(
            app: $this->app,
            company: $this->company,
            vendorTaxId: (string) ReceivedEcfCompliance::vendorTaxId($bill),
            encf: (string) ReceivedEcfCompliance::encf($bill),
            accepted: $accepted,
            rejectionReason: $reason,
        )->execute();

        return $approvalId !== '' ? $approvalId : null;
    }

    private function client(): Client
    {
        return new Client($this->app, $this->company, environment: $this->environment);
    }
}
