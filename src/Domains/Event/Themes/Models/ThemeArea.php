<?php

declare(strict_types=1);

namespace Kanvas\Event\Themes\Models;

use Kanvas\Event\Models\BaseModel;

class ThemeArea extends BaseModel
{
    protected $table = 'themes_areas';
    protected $guarded = [];

    protected $is_deleted;
}
