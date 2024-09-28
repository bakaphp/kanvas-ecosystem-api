<?php

declare(strict_types=1);

namespace Kanvas\Event\Themes\Models;

use Kanvas\Event\Models\BaseModel;

/**
 * @todo All these classes that are just tagging should be move
 * to social tags, and add tag type
 */
class Theme extends BaseModel
{
    protected $table = 'themes';
    protected $guarded = [];

    protected $is_deleted;
}
