<?php

declare(strict_types=1);

namespace Kanvas\Inventory\ProductsTypes\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Str;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;

class ResolveProductTypeAction
{
    public function __construct(private readonly AppInterface $app, private readonly CompanyInterface $company, private readonly UserInterface $user)
    {
    }

    public function execute(?int $id = null, ?string $name = null): ProductsTypes
    {
        $query = ProductsTypes::fromApp($this->app)
            ->notDeleted()
            ->whereIn('companies_id', [0, $this->company->getId()])
            ->orderByDesc('companies_id');

        if ($id !== null) {
            return $query->where('id', $id)->first()
                ?? throw new ModelNotFoundException('Product type not found in the current app and company or global catalog: ' . $id);
        }

        $slug = Str::slug($name ?? '');
        if ($slug === '') {
            throw new ValidationException('A product type ID or valid name is required.');
        }
        $productType = $query->where('slug', $slug)->first();

        return $productType ?? ProductsTypes::create([
            'slug' => $slug,
            'apps_id' => $this->app->getId(),
            'companies_id' => $this->company->getId(),
            'name' => $name,
            'users_id' => $this->user->getId(),
            'description' => $name . ' product type',
            'weight' => 0,
        ]);
    }
}
