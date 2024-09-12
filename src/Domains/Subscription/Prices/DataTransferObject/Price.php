<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Prices\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Spatie\LaravelData\Data;

class Price extends Data
{
    public function __construct(
        public CompanyInterface $company,
        public AppInterface $app,
        public UserInterface $user,
        public int $apps_plans_id,
        public string $stripe_id,
        public float $amount,
        public string $currency,
        public string $interval,
        public ?bool $is_default = false
    ) {
    }

    /**
     * Create a new Price DTO from request data.
     */
    public static function viaRequest(array $request, UserInterface $user): self
    {
        return new self(
            isset($request['company_id']) ? Companies::getById($request['company_id']) : $user->getCurrentCompany(),
            app(Apps::class),
            auth()->user,
            $request['apps_plans_id'],
            $request['stripe_id'],
            $request['amount'],
            $request['currency'],
            $request['interval'],
            $request['is_default'] ?? false
        );
    }
}
