<?php

declare(strict_types=1);

namespace Kanvas\Languages\Services;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use Kanvas\Languages\DataTransferObject\Translate as TranslateDto;
use Kanvas\Languages\Models\Languages;

class Translation
{
    /**
     * update.
     */
    public static function updateTranslation(EloquentModel $model, TranslateDto $dto, string $code): EloquentModel
    {
        $language = Languages::getByCode($code);

        foreach ($dto as $key => $value) {
            if (!in_array($key, $model->getTranslatableAttributes())) {
                unset($dto->$key);
            }
        }

        foreach ($dto->toArray() as $key => $value) {
            $model->setTranslation($key, $language->code, $value);
            $model->save();
        }

        return $model;
    }
}
