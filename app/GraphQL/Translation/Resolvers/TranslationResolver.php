<?php

declare(strict_types=1);

namespace App\GraphQL\Translation\Resolvers;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Languages\Models\Languages;

class TranslationResolver
{
    public function translation(Model $entity, array $args)
    {
        $languageCode = $args['languageCode'];
        $language = Languages::where('code', $languageCode)->first();

        $response = array_reduce($entity->getTranslatableAttributes(), function($result, $attribute) use ($entity, $language) {
            //WithoutFallback Allow the translation to try get a non-existed locale.
            $result[$attribute] = $entity->getTranslationWithoutFallback($attribute, strtolower($language->code)) ?? null;
            return $result;
        });

        $response['language'] = [
            'code' => $language->code,
            'language' => $language->name,
        ];

        return $response;
    }
}
