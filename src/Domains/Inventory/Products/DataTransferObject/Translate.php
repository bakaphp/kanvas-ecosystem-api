<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\DataTransferObject;

use Baka\Contracts\CompanyInterface;
use Illuminate\Contracts\Container\BindingResolutionException;
use Kanvas\Exceptions\ModelNotFoundException;
use Spatie\LaravelData\Data;

class Translate extends Data
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        public ?string $name = null,
        public ?string $description = null,
        public ?string $short_description = null,
        public ?string $html_description = null,
        public ?string $warranty_terms = null,
    ) {
    }

    /**
     * @psalm-suppress ArgumentTypeCoercion
     * @throws BindingResolutionException
     * @throws ModelNotFoundException
     */
    public static function viaRequest(array $request, CompanyInterface $company): self
    {
        return new self(
            $request['name'] ?? null,
            $request['description'] ?? null,
            $request['short_description'] ?? null,
            $request['html_description'] ?? null,
            $request['warranty_terms'] ?? null,
        );
    }
}
