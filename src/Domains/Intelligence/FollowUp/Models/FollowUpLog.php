<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\Models;

use Baka\Casts\Json;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Intelligence\Models\BaseModel;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Messages\Models\Message;

/**
 * Class FollowUpLog
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property int $leads_id
 * @property int|null $follow_ups_id
 * @property int|null $follow_up_days_id
 * @property int $pipeline_stages_id
 * @property int|null $sessions_id
 * @property bool $entered_follow_up_action
 * @property bool $found_follow_up
 * @property bool $found_follow_up_day
 * @property bool $entered_create_message_action
 * @property bool|null $should_respond
 * @property bool $message_created
 * @property bool $message_sent
 * @property int|null $messages_id
 * @property string|null $communication_channel
 * @property string|null $error_message
 * @property array|null $metadata
 */
class FollowUpLog extends BaseModel
{
    protected $table = 'follow_up_logs';
    protected $guarded = [];

    protected $casts = [
        'entered_follow_up_action' => 'boolean',
        'found_follow_up' => 'boolean',
        'found_follow_up_day' => 'boolean',
        'entered_create_message_action' => 'boolean',
        'should_respond' => 'boolean',
        'message_created' => 'boolean',
        'message_sent' => 'boolean',
        'metadata' => Json::class,
    ];

    public function app(): BelongsTo
    {
        return $this->belongsTo(Apps::class, 'apps_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Companies::class, 'companies_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'leads_id');
    }

    public function followUp(): BelongsTo
    {
        return $this->belongsTo(FollowUp::class, 'follow_ups_id');
    }

    public function followUpDay(): BelongsTo
    {
        return $this->belongsTo(FollowUpDay::class, 'follow_up_days_id');
    }

    public function pipelineStage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'pipeline_stages_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class, 'sessions_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'messages_id');
    }
}
