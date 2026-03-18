<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Mutations\Categories;

use Baka\Traits\ResolvesTargetCompanyTrait;
use Kanvas\Inventory\Categories\Actions\CreateCategory as CreateCategoryAction;
use Kanvas\Inventory\Categories\DataTransferObject\Categories as CategoriesDto;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Categories\Repositories\CategoriesRepository;
use Kanvas\Languages\DataTransferObject\Translate;
use Kanvas\Languages\Services\Translation as TranslationService;

class Category
{
    use ResolvesTargetCompanyTrait;

    /**
     * create.
     *
     */
    public function create(mixed $root, array $request): Categories
    {
        $request = $request['input'];

        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $targetCompaniesId = $this->resolveTargetCompaniesId($user, $request);

        unset($request['companies_id']);

        $category = (new CreateCategoryAction(
            CategoriesDto::viaRequest($request, $user, $company),
            $user
        ))->execute();

        $this->applyTargetCompaniesId($category, $targetCompaniesId);

        return $category;
    }

    /**
     * update.
     *
     */
    public function update(mixed $root, array $request): Categories
    {
        $category = CategoriesRepository::getById((int) $request['id'], auth()->user()->getCurrentCompany());
        $category->update($request['input']);
        return $category;
    }

    /**
     * delete.
     *
     */
    public function delete(mixed $root, array $request): bool
    {
        $category = CategoriesRepository::getById((int) $request['id'], auth()->user()->getCurrentCompany());
        return $category->delete();
    }

    /**
     * update.
     */
    public function updateCategoryTranslation(mixed $root, array $req): Categories
    {
        $company = auth()->user()->getCurrentCompany();

        $category = CategoriesRepository::getById((int) $req['id'], $company);
        $categoryTranslateDto = Translate::fromMultiple($req['input'], $company);

        $response = TranslationService::updateTranslation(
            model: $category,
            dto: $categoryTranslateDto,
            code: $req['code']
        );

        return $response;
    }
}
