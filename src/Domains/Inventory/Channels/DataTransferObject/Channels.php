<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Channels\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Enums\StateEnums;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Spatie\LaravelData\Data;

class Channels extends Data
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
        public string $name,
        public ?string $description = null,
        public ?string $slug = null,
        public bool $is_default = false,
        public bool $is_published = true,
    ) {
    }

    public static function viaRequest(array $request): self
    {
        return new self(
            app(Apps::class),
            isset($request['company_id']) ? Companies::getById($request['company_id']) : auth()->user()->getCurrentCompany(),
            auth()->user(),
            $request['name'],
            $request['description'] ?? null,
            $request['is_default'] ?? (bool) StateEnums::NO->getValue(),
            $request['is_published'] ?? (bool) StateEnums::YES->getValue(),
        );
    }
}
