<?php
declare(strict_types=1);

namespace  Kanvas\Social\Channels\Models;

use Kanvas\Social\Models\BaseModel;

class Channel extends BaseModel
{
    protected $table = "channels";

    protected $guarded = [];
}
