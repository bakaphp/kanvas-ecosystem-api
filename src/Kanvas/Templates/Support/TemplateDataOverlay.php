<?php

declare(strict_types=1);

namespace Kanvas\Templates\Support;

use Kanvas\Templates\Contracts\ExposesTemplateData;
use Override;

/**
 * An object shown to templates with some of its fields replaced. A hand-made blade row reads the
 * object live (`$app->name`), a twig row reads it flattened by PlainData, so a notification that
 * swaps one field cannot hand either engine a plain array: the overlay proxies the rest for blade
 * and merges the overrides into the flattened data for twig.
 */
final class TemplateDataOverlay implements ExposesTemplateData
{
    /**
     * @param array<string, mixed> $overrides
     */
    public function __construct(
        private readonly object $inner,
        private readonly array $overrides,
    ) {
    }

    #[Override]
    public function toTemplateData(): array
    {
        return [...PlainData::from($this->inner), ...$this->overrides];
    }

    public function __get(string $name): mixed
    {
        return array_key_exists($name, $this->overrides)
            ? $this->overrides[$name]
            : $this->inner->{$name};
    }

    public function __isset(string $name): bool
    {
        return array_key_exists($name, $this->overrides) || isset($this->inner->{$name});
    }

    public function __call(string $method, array $arguments): mixed
    {
        return $this->inner->{$method}(...$arguments);
    }
}
