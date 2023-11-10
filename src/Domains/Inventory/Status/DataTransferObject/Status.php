<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Status\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Enums\StateEnums;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Spatie\LaravelData\Data;

class Status extends Data
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
        public bool $is_default = false
    ) {
    }

    public static function viaRequest(array $request): self
    {
        return new self(
            app(Apps::class),
            isset($request['company_id']) ? Companies::getById($request['company_id']) : auth()->user()->getCurrentCompany(),
            auth()->user(),
            $request['name'],
            $request['is_default'] ?? (bool) StateEnums::NO->getValue(),
        );
    }
}
