<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\Insurance\Queries;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\UniversalSeguros\Services\UniversalSegurosService;
use Kanvas\Users\Models\Users;

class InsuranceReferenceQuery
{
    public function vehicleModels(mixed $rootValue, array $request): array
    {
        return $this->service()->getVehicleModels(
            (string) ($request['brand'] ?? ''),
            (string) ($request['model'] ?? '')
        );
    }

    public function provinces(mixed $rootValue, array $request): array
    {
        return $this->service()->getProvincias();
    }

    public function municipalities(mixed $rootValue, array $request): array
    {
        return $this->service()->getMunicipios((string) $request['province']);
    }

    public function sectors(mixed $rootValue, array $request): array
    {
        return $this->service()->getSectores(
            (string) $request['province'],
            (string) $request['municipality']
        );
    }

    public function accessories(mixed $rootValue, array $request): array
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
