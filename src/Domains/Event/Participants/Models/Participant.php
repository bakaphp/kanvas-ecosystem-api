<?php

declare(strict_types=1);

namespace Kanvas\Event\Participants\Models;

use Baka\Traits\HasLightHouseCache;
use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Event\Models\BaseModel;
use Kanvas\Event\Participants\Observers\ParticipantObserver;
use Kanvas\Event\Themes\Models\ThemeArea;
use Kanvas\Filesystem\Models\FilesystemEntities;
use Kanvas\Filesystem\Repositories\FilesystemEntitiesRepository;
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

    public function getPhoto(): ?FilesystemEntities
    {
        $app = app(Apps::class);
        $defaultAvatarId = $app->get(AppSettingsEnums::DEFAULT_USER_AVATAR->getValue());

        return $this->getFileByName('photo')
            ?? ($this->people?->getPhoto())
            ?? ($defaultAvatarId ? FilesystemEntitiesRepository::getFileFromEntityById($defaultAvatarId) : null);
    }

    #[Override]
    public function getGraphTypeName(): string
    {
        return 'Participant';
    }
}
