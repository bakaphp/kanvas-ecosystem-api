<?php

declare(strict_types=1);

namespace Tests\Stubs\Scribe;

use Kanvas\Scribe\ElectronicInvoicing\Contracts\FiscalPayloadInterface;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\FiscalDocumentTypeInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\ChecksIssuedNumbersInterface;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\EcfProviderInterface;
use Kanvas\Scribe\ElectronicInvoicing\DataTransferObject\EcfSubmission;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfEnvironmentEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfStatusEnum;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfTypeEnum;
use Throwable;

/**
 * In-memory e-CF provider. Tests set what the next submit / fetch answers and read back what was sent.
 * Static because the resolver constructs it by class name; reset() in setUp.
 */
class FakeEcfProvider implements EcfProviderInterface, ChecksIssuedNumbersInterface
{
    /**
     * The country it issues for, so a test can stand up a second regime on the same fake.
     */
    public static string $country = 'DO';

    public static EcfStatusEnum $submitStatus = EcfStatusEnum::ACCEPTED;
    public static EcfStatusEnum $fetchStatus = EcfStatusEnum::ACCEPTED;

    /** @var string[] */
    public static array $messages = [];

    public static ?string $pdfBase64 = null;

    public static ?bool $numberConsumed = null;

    public static bool $reachedAuthority = true;

    public static ?Throwable $submitException = null;

    public static ?Throwable $fetchException = null;

    /**
     * DGII's registry; null registers every number, an array only its keys.
     *
     * @var array<string, string>|null
     */
    public static ?array $taxpayers = null;

    /** @var list<FiscalPayloadInterface> */
    public static array $submitted = [];

    /** @var list<FiscalPayloadInterface> */
    public static array $fetched = [];

    /** @var list<string> e-NCFs the provider already holds, as if issued outside Kanvas */
    public static array $issuedNumbers = [];

    /** @var list<string> every e-NCF isNumberIssued() was asked about */
    public static array $issuedLookups = [];

    public static ?Throwable $issuedLookupException = null;

    /** @var list<EcfEnvironmentEnum|null> the environment each constructed provider was built for */
    public static array $environments = [];

    public function __construct(
        public readonly Apps $app,
        public readonly Companies $company,
        public readonly ?EcfEnvironmentEnum $environment = null,
    ) {
        self::$environments[] = $environment;
    }

    public static function reset(): void
    {
        self::$country = 'DO';
        self::$submitStatus = EcfStatusEnum::ACCEPTED;
        self::$fetchStatus = EcfStatusEnum::ACCEPTED;
        self::$messages = [];
        self::$pdfBase64 = null;
        self::$numberConsumed = null;
        self::$reachedAuthority = true;
        self::$submitException = null;
        self::$fetchException = null;
        self::$taxpayers = null;
        self::$submitted = [];
        self::$fetched = [];
        self::$environments = [];
        self::$issuedNumbers = [];
        self::$issuedLookups = [];
        self::$issuedLookupException = null;
    }

    public static function country(): string
    {
        return self::$country;
    }

    public function requestPayload(FiscalPayloadInterface $document): array
    {
        return ['fake' => true, 'systemRef' => $document->systemRef(), 'encf' => $document->number()];
    }

    public function submit(FiscalPayloadInterface $document): EcfSubmission
    {
        self::$submitted[] = $document;

        if (self::$submitException !== null) {
            throw self::$submitException;
        }

        return $this->answer($document, self::$submitStatus);
    }

    public function fetchResult(FiscalPayloadInterface $document): EcfSubmission
    {
        self::$fetched[] = $document;

        if (self::$fetchException !== null) {
            throw self::$fetchException;
        }

        return $this->answer($document, self::$fetchStatus);
    }

    public function isNumberIssued(FiscalDocumentTypeInterface $type, string $number): bool
    {
        self::$issuedLookups[] = $number;

        if (self::$issuedLookupException !== null) {
            throw self::$issuedLookupException;
        }

        return in_array($number, self::$issuedNumbers, true);
    }

    public function lookupTaxpayer(string $taxId): ?string
    {
        return self::$taxpayers === null ? 'CONTRIBUYENTE DE PRUEBA SRL' : (self::$taxpayers[$taxId] ?? null);
    }

    private function answer(FiscalPayloadInterface $document, EcfStatusEnum $status): EcfSubmission
    {
        return new EcfSubmission(
            status: $status,
            encf: $document->number(),
            providerReference: 'fake-' . $document->systemRef(),
            securityCode: $status->isAccepted() ? 'ABC123' : null,
            signedAt: $status->isAccepted() ? '2026-06-01T12:00:00-04:00' : null,
            qrUrl: $status->isAccepted() ? 'https://ecf.dgii.gov.do/qr?e=' . $document->number() : null,
            qrLegends: $status->isAccepted() ? ['Código de seguridad: ABC123', 'Fecha de firma: 01-06-2026'] : [],
            messages: self::$messages,
            pdfBase64: self::$pdfBase64,
            reachedAuthority: self::$reachedAuthority,
            numberConsumed: $status === EcfStatusEnum::REJECTED ? self::$numberConsumed : null,
            rawResponse: ['fake_status' => $status->value, 'systemRef' => $document->systemRef()],
        );
    }
}
