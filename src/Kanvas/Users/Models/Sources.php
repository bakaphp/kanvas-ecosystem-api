<?php

declare(strict_types=1);

namespace Kanvas\Users\Models;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;
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

    public static function getByName(string $name, ?AppInterface $app = null): self
    {
        try {
            return self::where('title', $name)
                ->notDeleted()
                ->firstOrFail();
        } catch (ModelNotFoundException $e) {
            //we want to expose the not found msg
            throw new ExceptionsModelNotFoundException("No record found for $name");
        }
    }
}
