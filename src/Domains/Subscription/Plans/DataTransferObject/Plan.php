<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Plans\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Spatie\LaravelData\Data;

class Plan extends Data
{
    public function __construct(
        public CompanyInterface $company,
        public AppInterface $app,
        public UserInterface $user,
        public string $name,
        public ?string $description = null,
        public string $stripe_id,
        public bool $is_default = false,
        public bool $is_deleted = false,
    ) {
    }

    public static function viaRequest(array $request, UserInterface $user): self
    {
        return new self(
            isset($request['company_id']) ? Companies::getById($request['company_id']) : $user->getCurrentCompany(),
            app(Apps::class),
            auth()->user,
            $request['name'],
            $request['stripe_id'],
            $request['description'] ?? null,
            $request['is_default'] ?? false,
            $request['is_deleted'] ?? false
        );
    }
}
