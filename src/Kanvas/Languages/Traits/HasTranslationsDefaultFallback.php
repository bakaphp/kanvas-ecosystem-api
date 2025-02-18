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

            $attributeValue = $this->getAttributes()[$key] ?? null;

            $decodedValue = json_decode($attributeValue ?: '{}', true) ?? [];
            $fallbackLocale = config('app.fallback_locale');

            // Asegurar que siempre haya una traducción en el idioma de fallback
            if (! isset($decodedValue[$fallbackLocale])) {
                $decodedValue[$fallbackLocale] = $attributeValue;
            }

            return array_filter(
                $decodedValue,
                fn ($value, $locale) => $this->filterTranslations($value, $locale, $allowedLocales),
                ARRAY_FILTER_USE_BOTH
            );
        }

        return array_map(
            fn ($attribute) => $this->getTranslations($attribute, $allowedLocales),
            $this->getTranslatableAttributes()
        );
    }
}
