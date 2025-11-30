<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Categories\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Enums\StateEnums;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\SystemModules\Models\SystemModules;
use Spatie\LaravelData\Data;

class Categories extends Data
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        public AppInterface $app,
        public CompanyInterface $company,
        public UserInterface $user,
        public SystemModules $systemModule,
        public string $name,
        public ?int $parent_id = null,
        public int|string $position = 0,
        public bool $is_published = true,
        public int $weight = 0,
        public ?string $code = null,
        public ?string $slug = null,
    ) {
    }

    /**
     * fromArray.
     */
    public static function viaRequest(array $request, AppInterface $app, UserInterface $user, CompanyInterface $company, SystemModules $systemModule): self
    {
        return new self(
            app: $app,
            company: isset($request['companies_id']) ? Companies::getById($request['companies_id']) : $company,
            user: $user,
            systemModule: $systemModule,
            name: $request['name'],
            parent_id: $request['parent_id'] ?? null,
            position: $request['position'] ?? 0,
            is_published: $request['is_published'] ?? (bool) StateEnums::YES->getValue(),
            weight: $request['weight'] ?? 0,
            code: $request['code'] ?? null,
            slug: $request['slug'] ?? null
        );
    }
}
