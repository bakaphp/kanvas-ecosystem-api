<?php

declare(strict_types=1);

namespace Kanvas\Companies\CorporateApplications\Concerns;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;

trait HasLegacyCorporateKey
{
    public function legacyKey(): string
    {
        return 'movipass_corporate_' . str_replace('corporate_application_', '', $this->value);
    }

    protected function readKeyFrom(Model|AppInterface $source): mixed
    {
        return $source->get($this->value) ?? $source->get($this->legacyKey());
    }
}
