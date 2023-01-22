<?php
declare(strict_types=1);

namespace Kanvas\Inventory\Channels\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Enums\StateEnums;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;

class Channels
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
        public int $is_published = 1,
    ) {
    }

    /**
     * fromRequest.
     *
     * @param array $data
     *
     * @return self
     */
    public static function fromRequest(array $data) : self
    {
        return new self(
            app(Apps::class),
            isset($request['company_id']) ? Companies::getById($request['company_id']) : auth()->user()->getCurrentCompany(),
            auth()->user(),
            $data['name'],
            $data['description'] ?? null,
            $data['is_published'] ?? StateEnums::YES->getValue(),
        );
    }
}
