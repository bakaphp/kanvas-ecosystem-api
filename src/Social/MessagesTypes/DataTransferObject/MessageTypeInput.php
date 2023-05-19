<?php

declare(strict_types=1);

namespace Kanvas\Social\MessagesTypes\DataTransferObject;

use Spatie\LaravelData\Data;

class MessageTypeInput extends Data
{
    public function __construct(
        public int $apps_id = 0,
        public int $languages_id,
        public string $name,
        public string $verb,
        public string $template,
        public string $templates_plura,
    ) {
    }
}
