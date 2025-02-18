<?php

declare(strict_types=1);

namespace Kanvas\Languages\Traits;

use Spatie\Translatable\HasTranslations;

trait HasTranslationsDefaultFallback
{
    use HasTranslations;

    public function getTranslations(string $key = null, array $allowedLocales = null): array
    {
        if ($key !== null) {
            $this->guardAgainstNonTranslatableAttribute($key);

            return array_filter(
                json_decode($this->getAttributes()[$key] ?? '' ?: '{}',
                    true) ?: [config('app.fallback_locale') => $this->getAttributes()[$key] ?? null],
                fn($value, $locale) => $this->filterTranslations($value, $locale, $allowedLocales),
                ARRAY_FILTER_USE_BOTH,
            );
        }

        return array_reduce($this->getTranslatableAttributes(), function ($result, $item) use ($allowedLocales) {
            $result[$item] = $this->getTranslations($item, $allowedLocales);

            return $result;
        });
    }
}
