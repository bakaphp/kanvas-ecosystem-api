<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Humano\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Humano\Client;
use Kanvas\Connectors\Humano\DataTransferObject\QuoteRequest;

/**
 * One method per documented endpoint of the auto surface. No mapping happens here —
 * that is the provider's job, and it is the only class allowed to know what
 * `montoTotalProrrata` means.
 */
class HumanoService
{
    protected Client $client;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        $this->client = new Client($app, $company);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function quote(QuoteRequest $request): array
    {
        return $this->client->post('/cotizacion/productos/cotizar', $request->toArray());
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getQuote(string $numeroCotizacion): array
    {
        return $this->client->get('/cotizacion/detalle', ['nuCotizacion' => $numeroCotizacion]);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getProvincias(): array
    {
        return $this->client->get('/catalogos/catalogo/provincias');
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getMunicipios(string $cdProvincia): array
    {
        return $this->client->get('/catalogos/catalogo/municipios', ['cdProvincia' => $cdProvincia]);
    }

    /**
     * Note the pluralised segment — `/catalogos/catalogos/sectores`, unlike its two
     * siblings. That is their spelling, not a typo here.
     *
     * @return array<array-key, mixed>
     */
    public function getSectores(string $cdProvincia, string $cdMunicipio): array
    {
        return $this->client->get('/catalogos/catalogos/sectores', [
            'cdProvincia' => $cdProvincia,
            'cdMunicipio' => $cdMunicipio,
        ]);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getMarcas(string $cdPlan): array
    {
        return $this->client->get('/vehiculos/marcas', ['cdPlan' => $cdPlan]);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getModelos(string $cdPlan, string $cdMarca): array
    {
        return $this->client->get('/vehiculos/modelos', [
            'cdPlan' => $cdPlan,
            'cdMarca' => $cdMarca,
        ]);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getVersiones(string $cdPlan, string $cdMarca, string $cdModelo): array
    {
        return $this->client->get('/vehiculos/versiones', [
            'cdPlan' => $cdPlan,
            'cdMarca' => $cdMarca,
            'cdModelo' => $cdModelo,
        ]);
    }
}
