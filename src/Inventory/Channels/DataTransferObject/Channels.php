<?php
declare(strict_types=1);

namespace Kanvas\Inventory\Channels\DataTransferObject;

use Kanvas\Apps\Models\Apps;

class Channels
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        public int $companies_id,
        public int $apps_id,
        public int $users_id,
        public string $name,
        public ?string $description = null,
        public ?string $slug,
        public int $is_published,
    ) {
    }

    /**
     * fromArray.
     *
     * @param  array $data
     *
     * @return self
     */
    public static function fromArray(array $data) : self
    {
        return new self(
            $data['companies_id'] ?? auth()->user()->default_company,
            $data['apps_id'] ?? app(Apps::class)->id,
            $data['users_id'] ?? auth()->user()->id,
            $data['name'],
            $data['description'] ?? null,
            $data['slug'] ?? null,
            $data['is_published'] ?? 0,
        );
    }
}
