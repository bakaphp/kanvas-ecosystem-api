<?php

declare(strict_types=1);

namespace Kanvas\Languages\Traits;

use Spatie\Translatable\HasTranslations;

trait HasTranslationsDefaultFallback
{
    use HasTranslations;

    public function getTranslations(?string $key = null, ?array $allowedLocales = null): array
    {
        if ($key === null) {
            return array_map(
                fn ($attribute) => $this->getTranslations($attribute, $allowedLocales),
                $this->getTranslatableAttributes()
            );
        }

        $this->guardAgainstNonTranslatableAttribute($key);

        $attributeValue = $this->attributes[$key] ?? null;

        if (! $attributeValue) {
            return [];
        }

        $fallbackLocale = config('app.fallback_locale') ?? 'en';

        $isJson = is_string($attributeValue) &&
            ($attributeValue[0] ?? '') === '{' &&
            (substr($attributeValue, -1) === '}');

        $decodedValue = $isJson ? json_decode($attributeValue, true) : [$fallbackLocale => $attributeValue];

        // Only filter if we have allowedLocales
        if ($allowedLocales === null) {
            return $decodedValue;
        }

        return array_filter(
            $decodedValue,
            fn ($value, $locale) => $this->filterTranslations($value, $locale, $allowedLocales),
            ARRAY_FILTER_USE_BOTH
        );
    }
}
