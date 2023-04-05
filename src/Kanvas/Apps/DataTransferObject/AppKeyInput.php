<?php

declare(strict_types=1);

namespace Kanvas\Apps\DataTransferObject;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Spatie\LaravelData\Data;

class AppKeyInput extends Data
{
    /**
     * Construct function.
     */
    public function __construct(
        public string $name,
        public Apps $app,
        public UserInterface $user,
        public ?string $expiresAt = null
    ) {
    }
}
