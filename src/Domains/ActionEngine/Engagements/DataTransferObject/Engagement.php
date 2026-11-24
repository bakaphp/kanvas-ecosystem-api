<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Engagements\DataTransferObject;

use Baka\Contracts\EngageableInterface;
use Baka\Support\Str;
use InvalidArgumentException;
use Kanvas\ActionEngine\Engagements\Models\Engagement as EngagementModel;
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
        public ?Lead $lead,
        public string $action,
        public string $requestId,
        public string $source,
        public ActionStatusEnum $status,
        public ?People $people = null,
        public string|int|null $receiverId = null,
        public string|int|null $taskId = null,
        public string $via = 'copy',
        public array $data = [],
        public ?string $formType = null,
        public array|string $extraField = [],
        public array $extraData = [],
        public ?string $channelId = null,
        public ?EngagementModel $parentEngagement = null,
        public array $files = [],
        public array $filesUrl = [],
        public ?string $from = null,
        public ?EngageableInterface $reference = null,
    ) {
    }

    /**
     * The entity this engagement shares; lead callers only ever set `lead`.
     */
    public function getReference(): EngageableInterface
    {
        return $this->reference
            ?? $this->lead
            ?? throw new InvalidArgumentException('An engagement needs a reference (a lead or another engageable model).');
    }

    public static function fromMultiple(
        Apps $app,
        Companies $company,
        Users $user,
        Lead $lead,
        array $request,
        ?People $people = null,
        ?EngagementModel $parentEngagement = null,
    ): self {
        return new self(...[
            'app' => $app,
            'company' => $company,
            'user' => $user,
            'lead' => $lead,
            'people' => $people,
            'parentEngagement' => $parentEngagement,
            ...self::requestFields($request),
        ]);
    }

    /**
     * App and company come from the reference itself, so they can never disagree with it.
     * Disjoint from fromMultiple (Users first, four arguments) so Spatie's ::from() routing
     * cannot confuse the two.
     */
    public static function fromReference(
        Users $user,
        People $people,
        EngageableInterface $reference,
        array $request
    ): self {
        $app = $reference->getEngagementApp();
        $company = $reference->getEngagementCompany();

        if (! $app instanceof Apps || ! $company instanceof Companies) {
            throw new InvalidArgumentException('An engagement reference must belong to an app and a company.');
        }

        return new self(...[
            'app' => $app,
            'company' => $company,
            'user' => $user,
            'lead' => $reference instanceof Lead ? $reference : null,
            'people' => $people,
            'reference' => $reference,
            ...self::requestFields($request),
        ]);
    }

    private static function requestFields(array $request): array
    {
        return [
            'action' => $request['action'],
            'requestId' => $request['request_id'],
            'source' => $request['source'],
            'status' => ActionStatusEnum::from($request['status']),
            'receiverId' => $request['receiver_id'] ?? null,
            'taskId' => $request['task_id'] ?? null,
            'via' => $request['via'] ?? 'copy',
            'data' => $request['data'] ?? [],
            'formType' => $request['form_type'] ?? null,
            'extraField' => $request['extraField'] ?? [],
            'extraData' => $request['extraData'] ?? [],
            'channelId' => isset($request['data']['channel_id']) ? (string) $request['data']['channel_id'] : null,
            'files' => $request['files'] ?? [],
            'filesUrl' => $request['files_url'] ?? [],
            'from' => Str::trimToNull($request['from'] ?? null),
        ];
    }
}
