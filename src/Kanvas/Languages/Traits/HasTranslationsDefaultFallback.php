<?php

declare(strict_types=1);

namespace Kanvas\Languages\Traits;

use Baka\Support\Str;
use Kanvas\Inventory\Attributes\Models\AttributesValues;
use Kanvas\Inventory\Products\Models\ProductsAttributes;
use Kanvas\Inventory\Variants\Models\VariantsAttributes;
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
            $decodedValue = json_decode($attributeValue, true);

            if (is_array($decodedValue)) {
                // Detect stringified array inside JSON and fix it
                foreach ($decodedValue as $locale => $value) {
                    if (is_string($value) && Str::isJson($value)) {
                        $decodedValue[$locale] = json_decode($value, true, 512, JSON_BIGINT_AS_STRING);
                    }
                }
            }

            $allowedLanguageCodes = ['en', 'es', 'fr'];
            $hasLanguageKey = false;

            if (is_array($decodedValue)) {
                foreach (array_keys($decodedValue) as $key) {
                    if (in_array(strtolower($key), $allowedLanguageCodes)) {
                        $hasLanguageKey = true;

                        break;
                    }
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

    public function setAttribute($key, $value)
    {
        if (! $this->isTranslatableAttribute($key)) {
            return parent::setAttribute($key, $value);
        }

        /**
         * The issue with type array , it will save it as {"en":{"open":"06:00"},"close":"22:00"} instead of {"open":"06:00","close":"22:00"}
         * so we need to check if the value is an array and is not a list or is empty and the key is not an array cast
         * then we will set the translations.
         */
        $attributeClass = in_array(get_called_class(), [ProductsAttributes::class, VariantsAttributes::class, AttributesValues::class]);

        if (is_array($value) && (! array_is_list($value) || count($value) === 0) && ! $attributeClass) {
            return $this->setTranslations($key, $value);
        }

        return $this->setTranslation($key, $this->getLocale(), $value);
    }
}
