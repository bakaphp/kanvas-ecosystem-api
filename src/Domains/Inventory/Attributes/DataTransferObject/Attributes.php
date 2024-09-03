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
        public bool $isVisible = false,
        public bool $isSearchable = false,
        public bool $isFiltrable = false,
        public bool $isRequired = false,
    ) {
    }

    public static function viaRequest(array $request, UserInterface $user): self
    {
        return new self(
            isset($request['company_id']) ? Companies::getById($request['company_id']) : $user->getCurrentCompany(),
            app(Apps::class),
            auth()->user(),
            $request['name'],
            $request['slug'] ?? Str::slug($request['name']),
            isset($request['attribute_type']['id']) ? AttributesTypesRepository::getById((int) $request['attribute_type']['id'], $user->getCurrentCompany()) : null,
            $request['is_visible'] ?? false,
            $request['is_searchable'] ?? false,
            $request['is_filtrable'] ?? false,
            $request['is_required'] ?? false,
        );
    }
}
