<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\UniversalSeguros\Queries;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\UniversalSeguros\Services\UniversalSegurosService;
use Kanvas\Users\Models\Users;

class UniversalSegurosReferenceQuery
{
    public function vehicleModels(mixed $rootValue, array $request): array
    {
        return $this->service()->getVehicleModels(
            (string) ($request['marca'] ?? ''),
            (string) ($request['modelo'] ?? '')
        );
    }

    public function provincias(mixed $rootValue, array $request): array
    {
        return $this->service()->getProvincias();
    }

    public function municipios(mixed $rootValue, array $request): array
    {
        return $this->service()->getMunicipios((string) $request['provincia']);
    }

    public function sectores(mixed $rootValue, array $request): array
    {
        return $this->service()->getSectores(
            (string) $request['provincia'],
            (string) $request['municipio']
        );
    }

    public function aditamentos(mixed $rootValue, array $request): array
    {
        return $this->service()->getAditamentos();
    }

    protected function service(): UniversalSegurosService
    {
        /** @var Users $user */
        $user = auth()->user();

        return new UniversalSegurosService(app(Apps::class), $user->getCurrentCompany());
    }
}
