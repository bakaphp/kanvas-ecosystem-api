<?php

declare(strict_types=1);

namespace Kanvas\Users\DataTransferObject;

use Baka\Contracts\AppInterface;
use Spatie\LaravelData\Data;

class AdminInvite extends Data
{
    /**
     * __construct.
     **/
    public function __construct(
        public AppInterface $app,
        public string $email,
        public ?string $firstname,
        public ?string $lastname,
        public ?string $description,
        public ?string $email_template = null,
        public ?array $customFields = [],
    ) {
    }
}
