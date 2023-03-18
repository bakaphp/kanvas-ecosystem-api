<?php

declare(strict_types=1);

namespace Kanvas\Languages\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Kanvas\Locations\Models\Cities;
use Kanvas\Models\BaseModel;

/**
 * Languages Class.
 *
 * @property string $name
 * @property string $title
 * @property string $order
 */
class Languages extends BaseModel
{
    use Cachable;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'languages';
}
