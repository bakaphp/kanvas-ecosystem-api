<?php

declare(strict_types=1);

namespace Kanvas\Users\Models;

use Kanvas\Models\BaseModel;

/**
 * Sources Model.
 *
 * @property string $title
 * @property string $url
 * @property int $language_id
 */
class Sources extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'sources';

    protected $fillable = [
        'title',
        'url',
        'language_id',
    ];
}
