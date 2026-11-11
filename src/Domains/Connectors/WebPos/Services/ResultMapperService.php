<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WebPos\Services;

use Kanvas\Connectors\WebPos\Enums\DocumentStatusEnum;
use Kanvas\Connectors\WebPos\Support\ResponseField;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\ElectronicInvoicing\DataTransferObject\EcfSubmission;
use Kanvas\Scribe\ElectronicInvoicing\Exceptions\ProviderRejectedException;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfQrCode;

/**
 * One mapping for every place WebPOS answers with a document result: sendFileToProcess, GetResultFe,
 * GeteCFBySystemRef and the result webhook all carry the same fields.
 *
 * `authNumber` is DGII's trackId (there can be several per document), not an authorization number, and
 * `confirmationNbr` is WebPOS-internal — the DGII security code lives in the QR URL.
 */
class ResultMapperService
{
    /**
     * @param array<array-key, mixed> $response
     */
    public static function toSubmission(array $response, ?string $fallbackEncf = null): EcfSubmission
    {
        $status = self::status($response);
        $encf = ResponseField::string(
            $response,
            'feNumber',
            'cufe',
            'docNumber',
            'eCF'
        ) ?? $fallbackEncf;

        if ($encf === null) {
            throw new ValidationException('WebPOS result carries no e-NCF.');
        }

        $qrUrl = ResponseField::string($response, 'qrContent');
        $qrParams = EcfQrCode::params($qrUrl);

        return new EcfSubmission(
            status: $status->toEcfStatus(),
            encf: $encf,
            providerReference: ResponseField::string($response, 'authNumber', 'trackId'),
            securityCode: $qrParams['CodigoSeguridad'] ?? null,
            signedAt: $qrParams['FechaFirma'] ?? ResponseField::string($response, 'authDate'),
            qrUrl: $qrUrl,
            qrLegends: array_values(array_filter([
                ResponseField::string($response, 'qrL1'),
                ResponseField::string($response, 'qrL2'),
            ])),
            messages: self::messages($response),
            pdfBase64: ResponseField::string($response, 'pdf'),
            wasDuplicated: (bool) ($response['wasDuplicated'] ?? false),
            numberConsumed: self::numberConsumed($response),
            rawResponse: $response,
        );
    }

    /**
     * DGII's `secuenciaUtilizada`, as WebPOS relays it inside `dgiResp` (or flat). Absent stays null: unknown.
     *
     * @param array<array-key, mixed> $response
     */
    private static function numberConsumed(array $response): ?bool
    {
        $value = self::dgiResponse($response)['secuenciaUtilizada']
            ?? $response['secuenciaUtilizada']
            ?? null;

        return $value === null ? null : filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    }

    /**
     * `accepted: false` means WebPOS could not even transform our JSON — nothing reached DGII and the
     * e-NCF is not used, so it is an error to fix and resend, not a DGII rejection that burns the number.
     *
     * @param array<array-key, mixed> $response
     */
    private static function status(array $response): DocumentStatusEnum
    {
        if (array_key_exists('accepted', $response) && $response['accepted'] === false) {
            throw new ProviderRejectedException(
                'WebPOS did not accept the document: ' . (implode(' | ', self::messages($response)) ?: 'no message'),
                $response
            );
        }

        foreach (['dgiSts', 'status', 'sts'] as $key) {
            if (! isset($response[$key]) || ! is_numeric($response[$key])) {
                continue;
            }

            $status = DocumentStatusEnum::tryFrom((int) $response[$key]);
            if ($status !== null) {
                return $status;
            }
        }

        throw new ValidationException('WebPOS result carries no known status.');
    }

    /**
     * @param array<array-key, mixed> $response
     * @return string[]
     */
    private static function messages(array $response): array
    {
        $messages = [];

        foreach (['msg', 'dgiErrMsg'] as $key) {
            $value = $response[$key] ?? null;
            foreach ((array) $value as $message) {
                if (is_string($message) && trim($message) !== '') {
                    $messages[] = trim($message);
                }
            }
        }

        // An acceptance carries one message, code 0 with an empty text; that is not a message.
        foreach ((array) (self::dgiResponse($response)['mensajes'] ?? []) as $dgiMessage) {
            if (! is_array($dgiMessage) || trim((string) ($dgiMessage['valor'] ?? '')) === '') {
                continue;
            }

            $code = isset($dgiMessage['codigo']) ? '[' . $dgiMessage['codigo'] . '] ' : '';
            $messages[] = $code . trim((string) $dgiMessage['valor']);
        }

        return array_values(array_unique($messages));
    }

    /**
     * DGII's own answer, which WebPOS relays as a JSON string in `dgiResp` (or already decoded).
     *
     * @param array<array-key, mixed> $response
     * @return array<array-key, mixed>|null
     */
    private static function dgiResponse(array $response): ?array
    {
        $dgiResponse = $response['dgiResp'] ?? null;

        if (is_string($dgiResponse)) {
            $dgiResponse = json_decode($dgiResponse, true);
        }

        return is_array($dgiResponse) ? $dgiResponse : null;
    }
}
