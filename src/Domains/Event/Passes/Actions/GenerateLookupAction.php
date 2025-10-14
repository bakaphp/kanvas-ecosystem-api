<?php

declare(strict_types=1);

namespace Kanvas\Event\Passes\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Companies\Models\Companies;

class GenerateLookupAction
{
    public function __construct(
        protected AppInterface $apps,
        protected Companies $company,
        protected string $code
    ) {
    }

    public function execute(): string
    {
        $secret = $this->apps->key;
        $hmac = hash_hmac('sha256', $this->company->id . '|' . $this->code, $secret, true);

        // URL-safe base64 encoding
        $lookup = rtrim(strtr(base64_encode($hmac), '+/', '-_'), '=');

        // Truncate to 20 chars for shorter lookup
        return substr($lookup, 0, 20);
    }
}
