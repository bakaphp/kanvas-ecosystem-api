<?php

declare(strict_types=1);

namespace Kanvas\Languages\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Kanvas\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Kanvas\Languages\Factories\LanguagesFactory;

/**
 * Languages Class.
 *
 * @property string $name
 * @property string $title
 * @property string $order
 */
class Languages extends BaseModel
{
    // use Cachable;
    use HasFactory;
    //public $incrementing = false;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'languages';

    protected static function newFactory()
    {
        return LanguagesFactory::new();
    }
}
