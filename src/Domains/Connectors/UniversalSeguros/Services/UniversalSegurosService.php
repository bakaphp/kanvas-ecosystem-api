<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalSeguros\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\UniversalSeguros\Client;
use Kanvas\Connectors\UniversalSeguros\DataTransferObject\QuoteRequest;
use Kanvas\Connectors\UniversalSeguros\Enums\DocumentOperationEnum;
use Kanvas\Connectors\UniversalSeguros\Enums\DocumentTransactionEnum;

class UniversalSegurosService
{
    protected Client $client;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        $this->client = new Client($app, $company);
    }

    public function getVehicleModels(string $brand = '', string $model = ''): array
    {
        return $this->client->get(
            '/api/v1/vehiculo/modelos/aliado?numeroPagina=-1&marca=' . rawurlencode($brand) . '&modelo=' . rawurlencode($model)
        );
    }

    public function getRentCarOptions(string $codProd, string $codPlan, string $revPlan, string $codRamo): array
    {
        return $this->client->get(
            '/api/v1/vehiculo/rent-car/opciones?codProd=' . rawurlencode($codProd)
            . '&codPlan=' . rawurlencode($codPlan)
            . '&revPlan=' . rawurlencode($revPlan)
            . '&codRamo=' . rawurlencode($codRamo)
        );
    }

    public function getAdditions(): array
    {
        return $this->client->get('/api/v1/aditamentos');
    }

    public function getProvinces(): array
    {
        return $this->client->get('/api/v1/direcciones/provincias');
    }

    public function getMunicipalities(string $province): array
    {
        return $this->client->get('/api/v1/direcciones/municipios?Provincia=' . rawurlencode($province));
    }

    public function getSectors(string $province, string $municipality): array
    {
        return $this->client->get(
            '/api/v1/direcciones/sectores?Provincia=' . rawurlencode($province) . '&Municipio=' . rawurlencode($municipality)
        );
    }

    public function quote(QuoteRequest $request): array
    {
        return $this->client->post('/api/v1/cotizacion/aliado', $request->toArray());
    }

    public function getQuote(string $numeroCotizacion): array
    {
        return $this->client->get('/api/v1/cotizacion/aliado/' . rawurlencode($numeroCotizacion));
    }

    public function updateQuote(string $numeroCotizacion, QuoteRequest $request): array
    {
        return $this->client->put('/api/v1/cotizacion/aliado/' . rawurlencode($numeroCotizacion), $request->toArray());
    }

    public function uploadDocument(
        string $referencia,
        DocumentTransactionEnum $transaction,
        DocumentOperationEnum $operation,
        string $filePath
    ): array {
        return $this->client->uploadDocument(
            '/api/v1/documentos?tipoTransaccion=' . $transaction->value
            . '&tipoOperacion=' . $operation->value
            . '&referencia=' . rawurlencode($referencia),
            $filePath
        );
    }

    public function sendPaymentLinkEmail(string $numeroCotizacion): array
    {
        return $this->client->post('/api/v1/comunicacion/aliado/envios/registrar', [
            'identificacion' => $numeroCotizacion,
            'tipo' => 'Pago',
        ]);
    }

    public function getPaymentLink(string $numeroCotizacion): array
    {
        return $this->client->post('/api/v1/comunicacion/aliado/informacion', [
            'identificacion' => $numeroCotizacion,
            'tipo' => 'Pago',
        ]);
    }

    public function sendInspectionLinkEmail(string $numeroCotizacion): array
    {
        return $this->client->post('/api/v1/comunicacion/aliado/envios/registrar', [
            'identificacion' => $numeroCotizacion,
            'tipo' => 'InspeccionAliado',
        ]);
    }

    public function generatePaymentForm(
        string $numeroCotizacion,
        string $urlAprobado,
        string $urlCancelado,
        string $urlDeclinado
    ): array {
        return $this->client->post('/api/v1/pagos/generar-formulario/aliado/' . rawurlencode($numeroCotizacion), [
            'urlAprobado' => $urlAprobado,
            'urlCancelado' => $urlCancelado,
            'urlDeclinado' => $urlDeclinado,
        ]);
    }

    public function emit(string $numeroCotizacion): array
    {
        return $this->client->post('/api/v1/cotizacion/emitir/aliado/' . rawurlencode($numeroCotizacion), []);
    }

    public function getPolicy(string $numeroCotizacion): array
    {
        return $this->client->get('/api/v1/poliza/aliado/cotizacion/' . rawurlencode($numeroCotizacion));
    }
}
