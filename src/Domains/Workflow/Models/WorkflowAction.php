<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Kanvas\Workflow\Factories\ActionFactory;

class WorkflowAction extends BaseModel
{
    use HasFactory;

    protected $table = 'actions';

    protected $guarded = [];

    protected static function newFactory(): Factory
    {
        return ActionFactory::new();
    }
}
