<?php

declare(strict_types=1);

namespace Kanvas\Locations\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Kanvas\Models\BaseModel;

/**
 * Cities Class.
 *
 * @property string $name
 */

class Locales extends BaseModel
{
    use Cachable;
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'locales';
}
