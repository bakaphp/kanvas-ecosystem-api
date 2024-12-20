<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Regions\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Currencies\Models\Currencies;
use Spatie\LaravelData\Data;

class Region extends Data
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        public CompanyInterface $company,
        public AppInterface $app,
        public UserInterface $user,
        public Currencies $currency,
        public string $name,
        public string $short_slug,
        public ?string $settings = null,
        public int $is_default = 0,
        public ?string $slug = null,
    ) {
    }

    /**
     * fromArray.
     */
    public static function viaRequest(array $data): self
    {
        return new self(
            isset($data['companies_id']) ? Companies::getById($data['companies_id']) : auth()->user()->getCurrentCompany(),
            app(Apps::class),
            auth()->user(),
            Currencies::getById($data['currency_id']),
            $data['name'],
            $data['short_slug'] ?? '',
            $data['settings'] ?? null,
            $data['is_default'],
            $request['slug'] ?? null
        );
    }
}
