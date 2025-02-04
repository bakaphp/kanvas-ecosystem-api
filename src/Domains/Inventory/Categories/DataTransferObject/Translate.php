<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Categories\DataTransferObject;

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
        public ?string $name = null
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
        );
    }
}
