<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Attributes\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Attributes\Models\AttributesTypes as AttributesTypesModel;
use Kanvas\Inventory\Attributes\Repositories\AttributesTypesRepository;
use Spatie\LaravelData\Data;

class Attributes extends Data
{
    public function __construct(
        public CompanyInterface $company,
        public AppInterface $app,
        public UserInterface $user,
        public string $name,
        public string $slug,
        public ?AttributesTypesModel $attributeType,
        public bool $isVisible = true,
        public bool $isSearchable = true,
        public bool $isFiltrable = true,
    ) {
    }

    public static function viaRequest(array $request, UserInterface $user, AppInterface $app): self
    {
        return new self(
            isset($request['company_id']) ? Companies::getById($request['company_id']) : $user->getCurrentCompany(),
            app(Apps::class),
            auth()->user(),
            $request['name'],
            $request['slug'] ?? Str::slug($request['name']),
            isset($request['attribute_type']['id']) ? AttributesTypesModel::getById((int) $request['attribute_type']['id'], $app) : null,
            $request['is_visible'] ?? true,
            $request['is_searchable'] ?? true,
            $request['is_filtrable'] ?? true,
        );
    }
}
