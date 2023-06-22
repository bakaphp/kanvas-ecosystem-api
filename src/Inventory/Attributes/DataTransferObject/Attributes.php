<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Attributes\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Spatie\LaravelData\Data;

class Attributes extends Data
{
    public function __construct(
        public CompanyInterface $company,
        public AppInterface $app,
        public UserInterface $user,
        public string $name,
        public string $value,
    ) {
    }

    public static function viaRequest(array $request): self
    {
        return new self(
            isset($request['company_id']) ? Companies::getById($request['company_id']) : auth()->user()->getCurrentCompany(),
            app(Apps::class),
            auth()->user(),
            $request['name'],
            $request['value']
        );
    }
}
