<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalAssistance\Services;

use Baka\Contracts\AppInterface;
use Carbon\Carbon;
use Kanvas\Connectors\UniversalAssistance\Client;
use Kanvas\Connectors\UniversalAssistance\Enums\EstadoVoucherEnum;
use Kanvas\Connectors\UniversalAssistance\Enums\TipoDocumentoEnum;
use Kanvas\Connectors\UniversalAssistance\Enums\TipoVentaEnum;
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
        $this->validateVoucherData($voucherData);

        $voucherRequestData = [
            'NroControl' => $voucherData['control_number'],
            'PostProcesoFlag' => $voucherData['post_process_flag'] ?? 'N',
            'Vendedor' => $voucherData['seller'] ?? '',
            'Canal' => $voucherData['channel'],
            'TipoVenta' => $voucherData['sale_type'] ?? TipoVentaEnum::ANUAL->value,
            'Linea' => $voucherData['line'],
            'EstadoVoucher' => $voucherData['voucher_status'] ?? EstadoVoucherEnum::ACTIVO->value,
            'MotivoVoucher' => $voucherData['voucher_reason'] ?? 'Activo',
            'Facturacion' => $voucherData['billing_status'] ?? 'Pendiente Facturación',
            'MonedaLista' => $voucherData['currency'] ?? 'USD',
            
            // Applicant data
            'PaisResidenciaSolicitante' => $voucherData['applicant_residence_country'],
            'SexoSolicitante' => $this->getSexFromPerson($applicant),
            'TipoDocumentoSolicitante' => $voucherData['applicant_document_type'] ?? TipoDocumentoEnum::DNI->value,
            'TituloCortesiaSolicitante' => $this->getCourtesyTitle($applicant),
            'NombreSolicitante' => $applicant->firstname,
            'ApellidoSolicitante' => $applicant->lastname,
            'NumeroDocumentoSolicitante' => $voucherData['applicant_document_number'],
            'EmailSolicitante' => $applicant->email,
            'TelefonoSolicitante' => $applicant->getCustomField('phone')?->value ?? '',
            
            // Address data
            'Pais' => $voucherData['country'],
            'ProvEstado' => $voucherData['state_province'],
            'Ciudad' => $voucherData['city'] ?? '',
            'CodigoPostal' => $voucherData['postal_code'] ?? '',
            'Direccion' => $voucherData['address'] ?? '',
        ];

        // Add optional fields
        if (isset($voucherData['birth_date'])) {
            $voucherRequestData['FechaNacimientoSolicitante'] = Carbon::parse($voucherData['birth_date'])->format('m/d/Y');
        }

        if (isset($voucherData['start_date'])) {
            $voucherRequestData['FechaInicio'] = Carbon::parse($voucherData['start_date'])->format('m/d/Y');
        }

        if (isset($voucherData['end_date'])) {
            $voucherRequestData['FechaFin'] = Carbon::parse($voucherData['end_date'])->format('m/d/Y');
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
     * Get sex from person (M/F)
     */
    protected function getSexFromPerson(People $person): string
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

        // Default based on sex
        $sex = $this->getSexFromPerson($person);
        return $sex === 'M' ? 'Sr.' : 'Sra.';
    }

    /**
     * Validate voucher data
     */
    protected function validateVoucherData(array $voucherData): void
    {
        $requiredFields = [
            'control_number',
            'channel',
            'line',
            'applicant_residence_country',
            'applicant_document_number',
            'country',
            'state_province'
        ];

        foreach ($requiredFields as $field) {
            if (! isset($voucherData[$field]) || empty($voucherData[$field])) {
                throw new ValidationException("Missing required field: {$field}");
            }
        }
    }
}
