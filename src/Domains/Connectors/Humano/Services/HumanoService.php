<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Humano\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Humano\Client;
use Kanvas\Connectors\Humano\DataTransferObject\QuoteRequest;

class HumanoService
{
    protected Client $client;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        $this->client = new Client($app, $company);
    }

    public function quote(QuoteRequest $request): array
    {
        return $this->client->post('/cotizacion/productos/cotizar', $request->toArray());
    }

    public function getQuote(string $numeroCotizacion): array
    {
        return $this->client->get('/cotizacion/detalle', ['nuCotizacion' => $numeroCotizacion]);
    }

    public function getProvincias(): array
    {
        return $this->client->get('/catalogos/catalogo/provincias');
    }

    public function getMunicipios(string $cdProvincia): array
    {
        return $this->client->get('/catalogos/catalogo/municipios', ['cdProvincia' => $cdProvincia]);
    }

    public function getSectores(string $cdProvincia, string $cdMunicipio): array
    {
        return $this->client->get('/catalogos/catalogos/sectores', [
            'cdProvincia' => $cdProvincia,
            'cdMunicipio' => $cdMunicipio,
        ]);
    }

    public function getMarcas(string $cdPlan): array
    {
        return $this->client->get('/vehiculos/marcas', ['cdPlan' => $cdPlan]);
    }

    public function getModelos(string $cdPlan, string $cdMarca): array
    {
        return $this->client->get('/vehiculos/modelos', [
            'cdPlan' => $cdPlan,
            'cdMarca' => $cdMarca,
        ]);
    }

    public function getVersiones(string $cdPlan, string $cdMarca, string $cdModelo): array
    {
        return $this->client->get('/vehiculos/versiones', [
            'cdPlan' => $cdPlan,
            'cdMarca' => $cdMarca,
            'cdModelo' => $cdModelo,
        ]);
    }
}
