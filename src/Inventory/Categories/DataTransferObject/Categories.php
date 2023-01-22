<?php
declare(strict_types=1);

namespace Kanvas\Inventory\Categories\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Enums\StateEnums;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
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
        public string $name,
        public int $parent_id = 0,
        public int $position = 0,
        public int $is_published = 1,
        public ?string $code = null,
    ) {
    }

    /**
     * fromArray.
     *
     * @param  array $request
     *
     * @return self
     */
    public static function fromRequest(array $request) : self
    {
        return new self(
            app(Apps::class),
            isset($request['company_id']) ? Companies::getById($request['company_id']) : auth()->user()->getCurrentCompany(),
            auth()->user(),
            $request['name'],
            $request['parent_id'] ?? 0,
            $request['position'] ?? 0,
            $request['is_published'] ?? StateEnums::YES->getValue(),
            $request['code'] ?? null,
        );
    }
}
