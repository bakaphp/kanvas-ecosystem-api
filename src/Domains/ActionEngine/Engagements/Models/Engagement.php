<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Engagements\Models;

use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Models\BaseModel;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;

/**
 * Class Engagement.
 *
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $companies_id
 * @property int $users_id
 * @property int $companies_actions_id
 * @property int $message_id
 * @property int $leads_id
 * @property int $people_id
 * @property int $pipelines_stages_id
 * @property string $entity_uuid
 * @property string $slug
 */
class Engagement extends BaseModel
{
    use UuidTrait;

    protected $table = 'engagements';
    protected $guarded = [];

    public function companyAction(): BelongsTo
    {
        return $this->belongsTo(CompanyAction::class, 'companies_actions_id', 'id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id', 'id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'leads_id', 'id');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(People::class, 'people_id', 'id');
    }

    public static function getByMessageId(int|string $messageId): self
    {
        return self::query()
            ->where('message_id', $messageId)
            ->firstOrFail();
    }

    public static function getByMessage(Message $message): self
    {
        return self::query()
            ->where('message_id', $message->getId())
            ->firstOrFail();
    }
}
