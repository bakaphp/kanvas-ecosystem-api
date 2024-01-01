<?php

declare(strict_types=1);

namespace Kanvas\Notifications\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Templates\Models\Templates;
use Spatie\LaravelData\Data;

class NotificationType extends Data
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        public AppInterface $app,
        public UserInterface $user,
        public string $name,
        public string $description,
        public Templates $template,
        public array $channels = [],
        public float $weight = 0,
    ) {
    }
}
