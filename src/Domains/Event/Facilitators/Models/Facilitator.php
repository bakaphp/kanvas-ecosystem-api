<?php

declare(strict_types=1);

namespace Kanvas\Event\Facilitators\Models;

use Baka\Traits\HasLightHouseCache;
use Baka\Traits\HasPhotoWithPeopleFallback;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Event\Facilitators\Observers\FacilitatorObserver;
use Kanvas\Event\Models\BaseModel;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Social\Tags\Traits\HasTagsTrait;
use Override;

#[ObservedBy([FacilitatorObserver::class])]
class Facilitator extends BaseModel
{
    use UuidTrait;
    use HasTagsTrait;
    use HasLightHouseCache;
    use HasPhotoWithPeopleFallback;

    protected $table = 'facilitators';
    protected $guarded = [];

    public function people(): BelongsTo
    {
        return $this->belongsTo(People::class);
    }

    #[Override]
    public function getGraphTypeName(): string
    {
        return 'Facilitator';
    }
}
