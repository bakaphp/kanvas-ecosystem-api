<?php

declare(strict_types=1);

namespace Kanvas\Event\Facilitators\Models;

use Baka\Traits\HasLightHouseCache;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Event\Facilitators\Observers\FacilitatorObserver;
use Kanvas\Event\Models\BaseModel;
use Kanvas\Filesystem\Models\FilesystemEntities;
use Kanvas\Filesystem\Repositories\FilesystemEntitiesRepository;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Social\Tags\Traits\HasTagsTrait;
use Override;

#[ObservedBy([FacilitatorObserver::class])]
class Facilitator extends BaseModel
{
    use UuidTrait;
    use HasTagsTrait;
    use HasLightHouseCache;

    protected $table = 'facilitators';
    protected $guarded = [];

    public function people(): BelongsTo
    {
        return $this->belongsTo(People::class);
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
        return 'Facilitator';
    }
}
