<?php
declare(strict_types=1);

namespace Kanvas\Social\Interactions\DataTransferObject;

use Baka\Contracts\AppInterface;
use Spatie\LaravelData\Data;

class Interaction extends Data
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        public string $name,
        public AppInterface $app,
        public string $title,
        public ?string $description = null,
    ) {
    }
}
