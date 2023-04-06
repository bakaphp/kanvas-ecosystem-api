<?php

declare(strict_types=1);

namespace Kanvas\Apps\DataTransferObject;

use Spatie\LaravelData\Data;

class AppSmtpSettingsInput extends Data
{
    public function __construct(
        public string $host,
        public string $port,
        public string $username,
        public string $password,
        public string $encryption,
        public string $fromEmail,
        public string $fromName
    ) {
    }
}
