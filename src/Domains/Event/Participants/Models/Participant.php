<?php

declare(strict_types=1);

namespace Kanvas\Event\Participants\Models;

use Baka\Traits\HasLightHouseCache;
use Baka\Traits\HasPhotoWithPeopleFallback;
use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Event\Models\BaseModel;
use Kanvas\Event\Participants\Observers\ParticipantObserver;
use Kanvas\Event\Themes\Models\ThemeArea;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Social\Tags\Traits\HasTagsTrait;
use Override;

#[ObservedBy([ParticipantObserver::class])]
class Participant extends BaseModel
{
    use SlugTrait;
    use UuidTrait;
    use HasTagsTrait;
    use HasLightHouseCache;
    use HasPhotoWithPeopleFallback;

    protected $table = 'participants';
    protected $guarded = [];

    protected $is_deleted;

    public static function bootSlugTrait(): void
    {
        // Slug generation lives in ParticipantObserver (uses people->name).
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

    #[Override]
    public function getGraphTypeName(): string
    {
        return 'Participant';
    }
}
