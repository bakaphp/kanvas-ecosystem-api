<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Engagements\DataTransferObject;

use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapInputName(SnakeCaseMapper::class)]
class Engagement extends Data
{
    public function __construct(
        public Apps $app,
        public Companies $company,
        public Users $user,
        public Lead $lead,
        public string $action,
        public string $requestId,
        public string $source,
        public ActionStatusEnum $status,
        public ?People $people = null,
        public ?string $receiverId = null,
        public string|int|null $taskId = null,
        public string $via = 'copy',
        public array $data = [],
        public ?string $formType = null,
        public array|string $extraField = [],
        public array $extraData = [],
        public ?string $channelId = null
    ) {
    }

    public static function fromMultiple(
        Apps $app,
        Companies $company,
        Users $user,
        Lead $lead,
        array $request,
        ?People $people = null,
    ): self {
        return new self(
            lead: $lead,
            people: $people,
            action: $request['action'],
            requestId: $request['request_id'],
            source: $request['source'],
            status: ActionStatusEnum::from($request['status']),
            receiverId: $request['receiver_id'] ?? null,
            taskId: $request['task_id'] ?? null,
            via: $request['via'] ?? 'copy',
            data: $request['data'] ?? [],
            formType: $request['form_type'] ?? null,
            extraField: $request['extraField'] ?? [],
            extraData: $request['extraData'] ?? [],
            channelId: isset($request['data']['channel_id']) ? (string) $request['data']['channel_id'] : null,
            app: $app,
            company: $company,
            user: $user,
        );
    }
}
