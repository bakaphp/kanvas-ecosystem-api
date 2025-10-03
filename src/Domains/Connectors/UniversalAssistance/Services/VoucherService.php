<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalAssistance\Services;

use Baka\Contracts\AppInterface;
use Carbon\Carbon;
use Kanvas\Connectors\UniversalAssistance\Client;
use Kanvas\Connectors\UniversalAssistance\DataTransferObjects\VoucherData;
use Kanvas\Connectors\UniversalAssistance\Enums\DocumentTypeEnum;
use Kanvas\Connectors\UniversalAssistance\Enums\SaleTypeEnum;
use Kanvas\Connectors\UniversalAssistance\Enums\VoucherStatusEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Souk\Orders\Models\Order;

class VoucherService
{
    protected Client $client;

    public function __construct(
        protected AppInterface $app,
        protected Order $order
    ) {
        $this->client = new Client($app, $order->company);
    }

    /**
     * Create a new voucher
     */
    public function createVoucher(array $voucherData, People $applicant): array
    {
        // Use DTO for better structure
        $voucher = VoucherData::from([
            'controlNumber' => $voucherData['control_number'] ?? '',
            'channel' => $voucherData['channel'] ?? '',
            'line' => $voucherData['line'] ?? '',
            'applicantResidenceCountry' => $voucherData['applicant_residence_country'] ?? 'ARG',
            'applicantDocumentNumber' => $voucherData['applicant_document_number'] ?? $applicant->getCustomField('document_number')?->value ?? '',
            'country' => $voucherData['country'] ?? 'ARG',
            'stateProvince' => $voucherData['state_province'] ?? '',
            'postProcessFlag' => $voucherData['post_process_flag'] ?? 'N',
            'seller' => $voucherData['seller'] ?? '',
            'saleType' => $voucherData['sale_type'] ?? SaleTypeEnum::ANNUAL->value,
            'voucherStatus' => $voucherData['voucher_status'] ?? VoucherStatusEnum::ACTIVE->value,
            'voucherReason' => $voucherData['voucher_reason'] ?? 'Activo',
            'billingStatus' => $voucherData['billing_status'] ?? 'Pendiente Facturación',
            'currency' => $voucherData['currency'] ?? 'USD',
            'applicantDocumentType' => $voucherData['applicant_document_type'] ?? DocumentTypeEnum::DNI->value,
            'birthDate' => isset($voucherData['birth_date']) ? Carbon::parse($voucherData['birth_date']) : null,
            'startDate' => isset($voucherData['start_date']) ? Carbon::parse($voucherData['start_date']) : null,
            'endDate' => isset($voucherData['end_date']) ? Carbon::parse($voucherData['end_date']) : null,
            'city' => $voucherData['city'] ?? '',
            'postalCode' => $voucherData['postal_code'] ?? '',
            'address' => $voucherData['address'] ?? '',
        ]);

        $this->validateVoucherData($voucher, $applicant);

        $voucherRequestData = [
            'NroControl' => $voucher->controlNumber,
            'PostProcesoFlag' => $voucher->postProcessFlag,
            'Vendedor' => $voucher->seller,
            'Canal' => $voucher->channel,
            'TipoVenta' => $voucher->saleType,
            'Linea' => $voucher->line,
            'EstadoVoucher' => $voucher->voucherStatus,
            'MotivoVoucher' => $voucher->voucherReason,
            'Facturacion' => $voucher->billingStatus,
            'MonedaLista' => $voucher->currency,

            // Applicant data
            'PaisResidenciaSolicitante' => $voucher->applicantResidenceCountry,
            'SexoSolicitante' => $this->getGenderFromPerson($applicant),
            'TipoDocumentoSolicitante' => $voucher->applicantDocumentType,
            'TituloCortesiaSolicitante' => $this->getCourtesyTitle($applicant),
            'NombreSolicitante' => $applicant->firstname,
            'ApellidoSolicitante' => $applicant->lastname,
            'NumeroDocumentoSolicitante' => $voucher->applicantDocumentNumber,
            'EmailSolicitante' => $applicant->email,
            'TelefonoSolicitante' => $applicant->getCustomField('phone')?->value ?? '',

            // Address data
            'Pais' => $voucher->country,
            'ProvEstado' => $voucher->stateProvince,
            'Ciudad' => $voucher->city,
            'CodigoPostal' => $voucher->postalCode,
            'Direccion' => $voucher->address,
        ];

        // Add optional fields
        if ($voucher->birthDate) {
            $voucherRequestData['FechaNacimientoSolicitante'] = $voucher->birthDate->format('m/d/Y');
        }

        if ($voucher->startDate) {
            $voucherRequestData['FechaInicio'] = $voucher->startDate->format('m/d/Y');
        }

        if ($voucher->endDate) {
            $voucherRequestData['FechaFin'] = $voucher->endDate->format('m/d/Y');
        }

        return $this->client->createVoucher($voucherRequestData);
    }

    /**
     * Query voucher information
     */
    public function queryVoucher(array $queryParams): array
    {
        return $this->client->queryVoucher($queryParams);
    }

    /**
     * Generate voucher PDF
     */
    public function generateVoucherPdf(string $voucherNumber): array
    {
        return $this->client->generatePdf([
            'NumeroVoucher' => $voucherNumber
        ]);
    }

    /**
     * Get gender from person (M/F)
     */
    protected function getGenderFromPerson(People $person): string
    {
        $sex = $person->getCustomField('sex')?->value ?? $person->getCustomField('gender')?->value;

        if (! $sex) {
            // Try to infer from courtesy title or name
            $title = $this->getCourtesyTitle($person);
            return in_array($title, ['Sr.', 'Dr.']) ? 'M' : 'F';
        }

        return strtoupper(substr((string) $sex, 0, 1));
    }

    /**
     * Get courtesy title from person
     */
    protected function getCourtesyTitle(People $person): string
    {
        $title = $person->getCustomField('title')?->value;

        if ($title) {
            return (string) $title;
        }

        // Default based on gender
        $sex = $this->getGenderFromPerson($person);
        return $sex === 'M' ? 'Sr.' : 'Sra.';
    }

    /**
     * Validate voucher data
     */
    protected function validateVoucherData(VoucherData $voucherData, People $applicant): void
    {
        $errors = [];

        if (empty($voucherData->controlNumber)) {
            $errors[] = 'Control number is required';
        }

        if (empty($voucherData->channel)) {
            $errors[] = 'Channel is required';
        }

        if (empty($voucherData->line)) {
            $errors[] = 'Line is required';
        }

        if (empty($voucherData->applicantDocumentNumber)) {
            $errors[] = 'Applicant document number is required';
        }

        if (empty($applicant->email)) {
            $errors[] = 'Applicant email is required';
        }

        if ($voucherData->startDate && $voucherData->endDate && $voucherData->startDate >= $voucherData->endDate) {
            $errors[] = 'End date must be after start date';
        }

        if (! empty($errors)) {
            throw new ValidationException(implode(', ', $errors));
        }
    }
}
