<?php
declare(strict_types=1);
namespace Kanvas\Inventory\Categories\DataTransferObject;
use Kanvas\Apps\Models\Apps;

class Categories
{
    /**
     * __construct
     *
     * @return void
     */
    public function __construct(
        public int $apps_id,
        public int $companies_id,
        public ?int $parent_id = null,
        public string $name,
        public ?string $code = null,
        public int $position = 0
    ) {
    }

    /**
     * fromArray
     *
     * @param  array $request
     * @return self
     */
    public static function fromArray(array $request): self
    {
        return new self(
            $request['apps_id'] ?? app(Apps::class)->id,
            $request['companies_id'] ?? auth()->user()->default_company,
            $request['parent_id'] ?? null,
            $request['name'],
            $request['code'] ?? null,
            $request['position'] ?? 0
        );
    }
}
