<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Attributes\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Companies\Models\Companies;
use Kanvas\Enums\AppEnums;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;
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
            $attributeTypeId = $request['attribute_type']['id'] ?? null;

            if (empty($attributeTypeId)) {
                $attributeType = null;
            } else {
                $attributeType = AttributesTypesRepository::getById(
                    (int) $attributeTypeId,
                    $user->getCurrentCompany(),
                    $app
                );
            }
        } catch (ModelNotFoundException | ExceptionsModelNotFoundException $e) {
            try {
                $attributeType = AttributesTypesModel::where('id', $attributeTypeId)
                    ->where('companies_id', AppEnums::GLOBAL_COMPANY_ID->getValue())
                    ->where('apps_id', AppEnums::LEGACY_APP_ID->getValue())
                    ->firstOrFail();
            } catch (Exception $e) {
                throw new Exception("Attribute type {$attributeTypeId} not found in any company context", 0, $e);
            }
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
