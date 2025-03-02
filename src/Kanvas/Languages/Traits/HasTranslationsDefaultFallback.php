<?php

declare(strict_types=1);

namespace Kanvas\Languages\Traits;

use Baka\Support\Str;
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

        if ($attributeValue === null) {
            return [];
        }

        $fallbackLocale = config('app.fallback_locale') ?? 'en';

        $isJson = is_string($attributeValue) &&
            ($attributeValue[0] ?? '') === '{' &&
            (substr($attributeValue, -1) === '}');

        $decodedValue = null;
        if ($isJson) {
            // Check if any first-level key looks like a language code
            // Pattern matches ISO language codes (2-3 letter codes, with optional country/script extensions)
            $languagePattern = '/^[a-z]{2,3}(-[A-Z][a-z]{3})?(-[A-Z]{2})?$/';

            $decodedValue = json_decode($attributeValue, true);
            $hasLanguageKey = false;
            foreach (array_keys($decodedValue) as $key) {
                if (preg_match($languagePattern, $key)) {
                    $hasLanguageKey = true;

                    break;
                }
            }

            $isJson = $hasLanguageKey;
        }

        $decodedValue = $isJson && is_array($decodedValue)
            ? $decodedValue
            : [$fallbackLocale => (Str::isJson($attributeValue) ? json_decode($attributeValue, true) : $attributeValue)];

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
