<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Attributes\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Exception;
use Kanvas\Companies\Models\Companies;
use Kanvas\Enums\AppEnums;
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
    ) {
    }

    public static function fromMultiple(array $request, UserInterface $user, AppInterface $app): self
    {
        try {
            $attributeType = isset($request['attribute_type']['id']) ? AttributesTypesRepository::getById((int) $request['attribute_type']['id'], $user->getCurrentCompany(), $app) : null;
        } catch (Exception $e) {
            $attributeType = AttributesTypesModel::where('companies_id', AppEnums::GLOBAL_COMPANY_ID->getValue())
                ->where('apps_id', AppEnums::LEGACY_APP_ID->getValue())
                ->firstOrFail();
        }

        return new self(
            isset($request['company_id']) ? Companies::getById($request['company_id']) : $user->getCurrentCompany(),
            $app,
            $user,
            $request['name'],
            $request['slug'] ?? Str::slug($request['name']),
            $attributeType,
            $request['is_visible'] ?? false,
            $request['is_searchable'] ?? false,
            $request['is_filtrable'] ?? false,
        );
    }

    /**
     * @deprecated v2
     */
    public static function viaRequest(array $request, UserInterface $user, AppInterface $app): self
    {
        return self::fromMultiple($request, $user, $app);
    }
}
