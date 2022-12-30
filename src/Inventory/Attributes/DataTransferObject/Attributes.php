<?php
declare(strict_types=1);
namespace Kanvas\Inventory\Attributes\DataTransferObject;

use Spatie\LaravelData\Data;

class Attributes extends Data
{
    public function __construct(
        public string $name
    ) {
    }
}
