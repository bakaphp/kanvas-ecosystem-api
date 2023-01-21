<?php
declare(strict_types=1);

namespace Kanvas\Inventory\Channels\DataTransferObject;

use Baka\Enums\StateEnums;
use Kanvas\Apps\Models\Apps;

class Channels
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        public int $apps_id,
        public int $companies_id,
        public int $users_id,
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
            $data['apps_id'] ?? app(Apps::class)->getId(),
            $data['companies_id'] = auth()->user()->getCurrentCompany()->getId(),
            $data['users_id'] ?? auth()->user()->getId(),
            $data['name'],
            $data['description'] ?? null,
            $data['is_published'] ?? StateEnums::YES->getValue(),
        );
    }
}
