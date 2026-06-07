<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Sessions\Models;

use Baka\Casts\Json;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Models\BaseModel;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\SystemModules\Models\SystemModules;
use Override;

/**
 * Class Session
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property int $channel_id
 * @property int $agents_id
 * @property string $uuid
 * @property string $canal_id
 * @property string $entity_namespace
 * @property int $entity_id
 * @property string $json
 * @property string $content;
 */
class Session extends BaseModel
{
    protected $table = 'sessions';
    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'content' => Json::class,
            'user' => Json::class,
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agents_id');
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'channel_id');
    }

    public function entity(): ?Model
    {
        if (! $this->entity_id || ! $this->entity_namespace) {
            return null;
        }

        $legacyClassMap = SystemModules::convertLegacySystemModules($this->entity_namespace);

        return $legacyClassMap::getById($this->entity_id);
    }

    public function people(): ?People
    {
        return $this->entity_namespace === People::class
            ? $this->entity()
            : null;
    }

    /**
     * Returns the channel this session's history lives on (derived from the
     * uuid marker — sessions are created per-channel by the connector layer).
     *
     * @deprecated for outbound channel selection — use
     *             `Kanvas\Intelligence\FollowUp\Services\LeadOutboundChannelResolver`
     *             which decides outbound channel from the Lead's contacts +
     *             stage config + opt-outs, not from session history. This
     *             method remains valid for "what channel did this conversation
     *             happen on?" inspection.
     */
    public function getChannel(): string
    {
        if (str_contains($this->uuid, 'email')) {
            return 'email';
        } elseif (
            str_contains($this->uuid, 'twilio')
        ) {
            return 'sms';
        } elseif (str_contains($this->uuid, 'wa-chat')) {
            return 'whatsapp';
        } else {
            throw new Exception('Channel unrecognized');
        }
    }
}
