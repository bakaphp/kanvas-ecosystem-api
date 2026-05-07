<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules\Models;

use Kanvas\Workflow\Models\BaseModel;
use Kanvas\Workflow\Rules\Factories\ActionFactory;
use Override;

class Action extends BaseModel
{
    protected $table = 'actions';

    protected $guarded = [];

    #[Override]
    protected static function newFactory()
    {
        return ActionFactory::new();
    }
}
