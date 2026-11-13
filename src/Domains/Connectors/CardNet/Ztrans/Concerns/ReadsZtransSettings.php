<?php

declare(strict_types=1);

namespace Kanvas\Connectors\CardNet\Ztrans\Concerns;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Kanvas\Connectors\CardNet\Ztrans\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

trait ReadsZtransSettings
{
    protected readonly AppInterface $app;

    protected function setting(ConfigurationEnum $key, ?string $default = null): string
    {
        return Str::trimToNull((string) $this->app->get($key->value))
            ?? $default
            ?? throw new ValidationException('CardNet Ztrans setting ' . $key->value . ' is not configured.');
    }
}
