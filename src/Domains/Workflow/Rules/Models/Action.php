<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules\Models;

use Baka\Traits\SlugTrait;
use Kanvas\Workflow\Models\BaseModel;
use Kanvas\Workflow\Rules\Factories\ActionFactory;

class Action extends BaseModel
{
    use SlugTrait;

    protected $table = 'actions';

    protected $guarded = [];

    protected static function newFactory()
    {
        return ActionFactory::new();
    }
}
