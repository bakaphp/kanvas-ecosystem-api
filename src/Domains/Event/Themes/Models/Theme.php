<?php

declare(strict_types=1);

namespace Kanvas\Event\Themes\Models;

use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Event\Models\BaseModel;
use Kanvas\Workflow\Traits\CanUseWorkflow;

class Theme extends BaseModel
{
    use UuidTrait;
    use SlugTrait;
    use CanUseWorkflow;

    protected $table = 'evethements';
    protected $guarded = [];

    protected $is_deleted;

    
}
