<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Mutations\Categories;

use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Categories\Actions\CreateCategory as CreateCategoryAction;
use Kanvas\Inventory\Categories\DataTransferObject\Categories as CategoriesDto;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Categories\Repositories\CategoriesRepository;
use Kanvas\Languages\DataTransferObject\Translate;
use Kanvas\Languages\Services\Translation as TranslationService;
use Kanvas\SystemModules\Models\SystemModules;

class Category
{
    /**
     * create.
     */
    public function create(mixed $root, array $request): Categories
    {
        $request = $request['input'];

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $systemModule = SystemModules::where('id', $request['system_module_id'])
                                ->notDeleted()
                                ->fromPublicApp()
                                ->firstOrFail();

        if (! $user->isAppOwner()) {
            unset($request['companies_id']);
        }

        return (new CreateCategoryAction(
            CategoriesDto::viaRequest(
                app: $app,
                request: $request,
                user: $user,
                company: $company,
                systemModule: $systemModule
            ),
            $user
        ))->execute();
    }

    /**
     * update.
     */
    public function update(mixed $root, array $request): Categories
    {
        $category = CategoriesRepository::getById((int) $request['id'], auth()->user()->getCurrentCompany());
        $category->update($request['input']);

        return $category;
    }

    /**
     * delete.
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
