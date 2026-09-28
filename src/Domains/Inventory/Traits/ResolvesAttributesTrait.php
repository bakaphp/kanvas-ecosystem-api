<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Traits;

use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Inventory\Attributes\Actions\CreateAttribute;
use Kanvas\Inventory\Attributes\DataTransferObject\Attributes as AttributesDto;
use Kanvas\Inventory\Attributes\Models\Attributes;

/**
 * Shared by Products and Variants — both take the same `['id'|'name', 'value']` shape
 * from importers and GraphQL input.
 */
trait ResolvesAttributesTrait
{
    protected function resolveAttribute(UserInterface $user, array $attribute): ?Attributes
    {
        if (isset($attribute['id'])) {
            return Attributes::getById((int) $attribute['id'], $this->app);
        }

        if (empty($attribute['name'])) {
            return null;
        }

        $attributesDto = AttributesDto::from([
            'app' => $this->app,
            'user' => $user,
            'company' => $this->company,
            'name' => $attribute['name'],
            'value' => $attribute['value'],
            'isVisible' => true,
            'isSearchable' => true,
            'isFiltrable' => true,
            'slug' => Str::slug($attribute['name']),
        ]);

        return new CreateAttribute($attributesDto, $user)->execute();
    }
}
