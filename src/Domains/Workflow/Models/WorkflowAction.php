<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Kanvas\Workflow\Factories\ActionFactory;
use Kanvas\Workflow\Traits\CatalogedByHandler;

class WorkflowAction extends BaseModel
{
    use CatalogedByHandler;
    use HasFactory;

    protected $table = 'actions';

    protected $guarded = [];

    protected static function newFactory(): Factory
    {
        return ActionFactory::new();
    }
}
