<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WebPos\Providers;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\WebPos\Actions\SendCommercialResponseAction;
use Kanvas\Connectors\WebPos\Client;
use Kanvas\Connectors\WebPos\Enums\CommercialResponseEnum;
use Kanvas\Connectors\WebPos\Enums\ConfigurationEnum;
use Kanvas\Connectors\WebPos\Enums\EnvironmentEnum;
use Kanvas\Connectors\WebPos\Enums\HostEnum;
use Kanvas\Connectors\WebPos\Exceptions\WebPosRequestException;
use Kanvas\Connectors\WebPos\Services\FiscalDocMapperService;
use Kanvas\Connectors\WebPos\Services\ResultMapperService;
use Kanvas\Connectors\WebPos\Support\ResponseField;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\ChecksIssuedNumbersInterface;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\EcfProviderInterface;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\FiscalDocumentTypeInterface;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\FiscalPayloadInterface;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\RespondsToReceivedEcfInterface;
use Kanvas\Scribe\ElectronicInvoicing\DataTransferObject\EcfSubmission;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfEnvironmentEnum;
use Kanvas\Scribe\ElectronicInvoicing\Exceptions\FiscalNumberHeldElsewhereException;
use Kanvas\Scribe\ElectronicInvoicing\Exceptions\ProviderRejectedException;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DataTransferObject\EcfDocument;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DrFiscalRegime;

/**
 * systemRef is the attempt's uuid: a repeated submit of one attempt comes back `wasDuplicated` with the status
 * WebPOS holds instead of issuing a second e-CF, and a new attempt (a resend after a reusable rejection, or a
 * re-issue) is never mistaken for an earlier one.
 */
class WebPosEcfProvider implements EcfProviderInterface, RespondsToReceivedEcfInterface, ChecksIssuedNumbersInterface
{
    public static function country(): string
    {
        return DrFiscalRegime::COUNTRY;
    }

    public function __construct(
        public readonly Apps $app,
        public readonly Companies $company,
        private ?Client $client = null,
        private readonly ?EcfEnvironmentEnum $environment = null,
    ) {
    }

    public function requestPayload(FiscalPayloadInterface $payload): array
    {
        $document = EcfDocument::narrow($payload);

        return $this->mapper($this->client())->map($document);
    }

    public function submit(FiscalPayloadInterface $payload): EcfSubmission
    {
        $document = EcfDocument::narrow($payload);

        $client = $this->client();

        try {
            $response = $client->post(
                HostEnum::ISSUING,
                sprintf(
                    'api/fedo/ak/v1/%s/sendFileToProcess/%s/%s',
                    $client->environment->pathSegment(),
                    $client->companyLicense,
                    Client::API_KEY
                ),
                $this->requestPayload($document),
            );
        } catch (WebPosRequestException $e) {
            if ($e->isPermissionDenied()) {
                throw new ProviderRejectedException(
                    'WebPOS refused the send: this API key has no permission to issue e-CF for license '
                    . "{$client->companyLicense} in {$client->environment->value}. WebPOS support grants it; nothing reached DGII."
                );
            }

            throw $e;
        }

        return ResultMapperService::toSubmission($response, $document->encf);
    }

    /**
     * By systemRef first: that is exactly our attempt. A lookup by number answers for whatever document holds
     * the number, which on a shared or reused range can be someone else's, so it only counts when it carries
     * our systemRef. getResultFe is v1: v2 is refused ("No tiene permisos") for keys without that grant.
     * WebPOS answers an unknown document with 200 and an empty body or empty fields, not 404.
     */
    public function fetchResult(FiscalPayloadInterface $payload): EcfSubmission
    {
        $document = EcfDocument::narrow($payload);

        $client = $this->client();

        $response = $this->knownDocument($client->get(
            HostEnum::ISSUING,
            sprintf(
                'api/fedo/ak/v1/geteCfBySystemRef/%s/%s/%d/%d/%s',
                $client->companyLicense,
                Client::API_KEY,
                $client->environment->iAmb(),
                (int) $document->type->value,
                rawurlencode($document->systemRef),
            ),
        ));

        $response ??= $this->knownDocument($client->get(
            HostEnum::ISSUING,
            sprintf(
                'api/fedo/ak/v1/%s/getResultFe/%s/%s/%s',
                $client->environment->pathSegment(),
                $client->companyLicense,
                Client::API_KEY,
                rawurlencode($document->encf),
            ),
        ));

        if ($response === null) {
            throw new ValidationException("WebPOS has no record of e-CF {$document->encf} (systemRef {$document->systemRef}).");
        }

        $heldBy = ResponseField::string($response, 'systemRef');
        if ($heldBy !== null && $heldBy !== $document->systemRef) {
            throw new FiscalNumberHeldElsewhereException(
                "e-NCF {$document->encf} is held at WebPOS by another document (systemRef {$heldBy}), not by this send "
                . "({$document->systemRef}). The number was used outside Kanvas; review the range before resending."
            );
        }

        return ResultMapperService::toSubmission($response, $document->encf);
    }

    public function isNumberIssued(FiscalDocumentTypeInterface $type, string $number): bool
    {
        $client = $this->client();

        return $this->knownDocument($client->get(
            HostEnum::ISSUING,
            sprintf(
                'api/fedo/ak/v1/%s/getResultFe/%s/%s/%s',
                $client->environment->pathSegment(),
                $client->companyLicense,
                Client::API_KEY,
                rawurlencode($number),
            ),
        )) !== null;
    }

    public function lookupTaxpayer(string $taxId): ?string
    {
        $taxId = (string) preg_replace('/\D/', '', $taxId);

        if ($taxId === '') {
            return null;
        }

        $response = $this->client()->get(HostEnum::ISSUING, 'api/fedo/ak/v1/checkRnc/' . Client::API_KEY . '/' . $taxId);

        return $response !== null
            ? ResponseField::string(
                $response,
                'name',
                'razonSocial',
                'nombre',
                'businessName',
                'customerName'
            )
            : null;
    }

    public function sendCommercialResponse(Bill $bill, bool $accepted, ?string $reason = null): ?string
    {
        $result = new SendCommercialResponseAction(
            bill: $bill,
            response: $accepted ? CommercialResponseEnum::APPROVE : CommercialResponseEnum::REJECT,
            reason: $reason,
            client: $this->client(),
        )->execute();

        return ResponseField::string($result, 'trackId', 'id');
    }

    /**
     * @param array<array-key, mixed>|null $response
     * @return array<array-key, mixed>|null
     */
    private function knownDocument(?array $response): ?array
    {
        return $response !== null && ResponseField::string($response, 'feNumber', 'cufe') !== null ? $response : null;
    }

    private function client(): Client
    {
        $environment = $this->environment !== null ? EnvironmentEnum::fromEcf($this->environment) : null;

        return $this->client ??= new Client($this->company, $environment);
    }

    private function mapper(Client $client): FiscalDocMapperService
    {
        return new FiscalDocMapperService(
            companyLicense: $client->companyLicense,
            branch: (string) $this->company->get(ConfigurationEnum::BRANCH->value),
            pos: (string) $this->company->get(ConfigurationEnum::POS->value),
        );
    }
}
