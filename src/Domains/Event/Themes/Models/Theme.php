<?php

declare(strict_types=1);

namespace Kanvas\Event\Themes\Models;

use Kanvas\Event\Models\BaseModel;

class Theme extends BaseModel
{
    protected $table = 'themes';
    protected $guarded = [];

    protected $is_deleted;
}