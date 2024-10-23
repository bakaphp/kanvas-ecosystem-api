<?php

declare(strict_types=1);

namespace Kanvas\Event\Participants\Models;

use Baka\Support\Str;
use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Event\Models\BaseModel;
use Kanvas\Event\Themes\Models\ThemeArea;
use Kanvas\Guild\Customers\Models\People;

class Participant extends BaseModel
{
    use SlugTrait;
    use UuidTrait;

    protected $table = 'participants';
    protected $guarded = [];

    protected $is_deleted;

    public static function bootSlugTrait()
    {
        static::creating(function ($model) {
            $model->slug = $model->slug ?? Str::slug("{$model->people->name} {$model->people->id}");
        });

        static::updating(function ($model) {
            $model->slug = $model->slug ?? Str::slug("{$model->people->name} {$model->people->id}");
        });
    }

    public function themeArea(): BelongsTo
    {
        return $this->belongsTo(ThemeArea::class);
    }

    public function people(): BelongsTo
    {
        return $this->belongsTo(People::class);
    }

    public function participantType(): BelongsTo
    {
        return $this->belongsTo(ParticipantType::class);
    }
}
