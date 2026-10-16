<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Entities;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use DateTime;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Elead\Client;
use Kanvas\Connectors\Elead\Support\EleadCache;

class SalesActivities
{
    public const string DATE_FORMAT = 'Y-m-d\TH:i:s.v\Z';

    public ?string $id = null;
    public ?string $activityId = null;
    public ?string $opportunityId = null;
    public ?string $activityType = null;
    public ?string $category = null;
    public ?string $name = null;
    public ?string $dueDate = null;
    public ?string $completedDate = null;
    public ?string $closedDate = null;
    public ?string $outcome = null;
    public ?string $createdBy = null;
    public ?string $assignedTo = null;
    public ?string $completedBy = null;
    public ?string $upType = null;
    public ?string $source = null;
    public ?string $subSource = null;
    public ?string $createdByUserId = null;
    public ?string $assignedToUserId = null;
    public ?string $completedByUserId = null;
    public ?Companies $company = null;
    public ?AppInterface $app = null;

    /**
     * Assign value to the current object.
     */
    public function assign(array $data): void
    {
        foreach ($data as $key => $value) {
            $this->{$key} = $value;
        }

        // The schedule/complete endpoints answer with `activityId`, the history endpoints with `id`.
        $this->activityId ??= $this->id;
        $this->id ??= $this->activityId;
    }

    public static function fromResponse(AppInterface $app, Companies $company, array $response): self
    {
        $activity = new self();
        $activity->company = $company;
        $activity->app = $app;
        $activity->assign($response);

        return $activity;
    }

    public function dueDate(): ?Carbon
    {
        return $this->dueDate !== null ? Carbon::parse($this->dueDate)->utc() : null;
    }

    public function isActivityType(array $typeNames): bool
    {
        foreach ($typeNames as $typeName) {
            if (Str::contains((string) $this->activityType, $typeName, ignoreCase: true)
                || Str::contains((string) $this->name, $typeName, ignoreCase: true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * get the current dateIn.
     */
    public static function currentDateIn(): string
    {
        $amNY = new DateTime('America/New_York');

        return str_replace('-04:00', '.402Z', $amNY->format('c'));
    }

    /**
     * Create a new schedule activity.
     */
    public static function create(AppInterface $app, Companies $company, array $data): self
    {
        $client = new Client($app, $company);
        $response = $client->post(
            '/sales/v1/elead/activities/schedule',
            $data,
        );

        return self::fromResponse($app, $company, $response);
    }

    /**
     * Create a complete activity.
     */
    public static function createComplete(AppInterface $app, Companies $company, array $data): self
    {
        $client = new Client($app, $company);
        $response = $client->post(
            '/sales/v1/elead/activities/complete',
            $data,
        );

        return self::fromResponse($app, $company, $response);
    }

    /**
     * Create message.
     */
    public function createMessage(array $message): array
    {
        $message['activityId'] = $this->activityId;

        $client = new Client($this->app, $this->company);
        $response = $client->post(
            '/sales/v1/elead/activities/message',
            $message,
        );

        return $response;
    }

    /**
     * Get available activity types.
     */
    public static function getActivityTypes(AppInterface $app, Companies $company, bool $fresh = false): array
    {
        $cache = new EleadCache($app, $company);

        if (! $fresh) {
            $cached = $cache->get('activity_types', 'all');

            if ($cached !== null) {
                return $cached;
            }
        }

        $client = new Client($app, $company);
        $response = $client->get('/sales/v1/elead/activities/types');

        $cache->setReference('activity_types', 'all', $response);

        return $response;
    }

    public static function getActivityOutcome(AppInterface $app, Companies $company, string $activityId): array
    {
        $client = new Client($app, $company);
        $response = $client->get("/sales/v1/elead/activities/{$activityId}/outcomes");

        return $response;
    }

    public static function findActivityTypeId(AppInterface $app, Companies $company, string $typeName): int|string|null
    {
        foreach (self::getActivityTypes($app, $company)['items'] ?? [] as $type) {
            if (Str::contains((string) $type['name'], $typeName)) {
                return $type['id'];
            }
        }

        return null;
    }

    /**
     * Get activity by activity ID.
     */
    public static function getById(AppInterface $app, Companies $company, string $activityId): self
    {
        $client = new Client($app, $company);
        $response = $client->get("/sales/v1/elead/activities/{$activityId}");

        return self::fromResponse($app, $company, $response);
    }

    /**
     * Get activity history by customer ID.
     */
    public static function getHistoryByCustomerId(
        AppInterface $app,
        Companies $company,
        string $customerId,
        array $queryParams = []
    ): array {
        $client = new Client($app, $company);
        $url = "/sales/v1/elead/activities/history/byCustomerId/{$customerId}";

        if (! empty($queryParams)) {
            $url .= '?' . http_build_query($queryParams);
        }

        return $client->get($url);
    }

    /**
     * Get activity history by opportunity ID.
     */
    public static function getHistoryByOpportunityId(
        AppInterface $app,
        Companies $company,
        string $opportunityId,
        array $queryParams = []
    ): array {
        $client = new Client($app, $company);
        $url = "/sales/v1/elead/activities/history/byOpportunityId/{$opportunityId}";

        if (! empty($queryParams)) {
            $url .= '?' . http_build_query($queryParams);
        }

        return $client->get($url);
    }

    /**
     * Get activity history by user ID.
     */
    public static function getHistoryByUserId(
        AppInterface $app,
        Companies $company,
        string $userId,
        array $queryParams = []
    ): array {
        $client = new Client($app, $company);
        $url = "/sales/v1/elead/activities/history/byUserId/{$userId}";

        if (! empty($queryParams)) {
            $url .= '?' . http_build_query($queryParams);
        }

        return $client->get($url);
    }

    /**
     * Update an existing activity.
     */
    public function update(array $updateData): array
    {
        if (! $this->activityId) {
            throw new InvalidArgumentException('Activity ID is required to update an activity');
        }

        $client = new Client($this->app, $this->company);
        $response = $client->post(
            "/sales/v1/elead/activities/update/{$this->activityId}",
            $updateData
        );

        return $response;
    }

    /**
     * Update an activity by activity ID (static method).
     */
    public static function updateById(
        AppInterface $app,
        Companies $company,
        string $activityId,
        array $updateData
    ): array {
        $client = new Client($app, $company);
        $response = $client->post(
            "/sales/v1/elead/activities/update/{$activityId}",
            $updateData
        );

        return $response;
    }

    /**
     * Add inbound call activity.
     */
    public static function addInboundCall(AppInterface $app, Companies $company, array $data): self
    {
        $client = new Client($app, $company);
        $response = $client->post(
            '/sales/v1/elead/activities/call/inbound',
            $data
        );

        return self::fromResponse($app, $company, $response);
    }

    /**
     * Add outbound call data to a scheduled activity and complete it.
     */
    public function addOutboundCall(array $outboundCallData): array
    {
        if (! $this->activityId) {
            throw new InvalidArgumentException('Activity ID is required to add outbound call data');
        }

        $data = [
            'activityId' => $this->activityId,
            'outboundCall' => $outboundCallData,
        ];

        $client = new Client($this->app, $this->company);
        $response = $client->post(
            "/sales/v1/elead/activities/{$this->activityId}/outbound-call",
            $data
        );

        return $response;
    }

    /**
     * Add outbound call data to a scheduled activity by activity ID (static method).
     */
    public static function addOutboundCallById(
        AppInterface $app,
        Companies $company,
        string $activityId,
        array $outboundCallData
    ): array {
        $data = [
            'activityId' => $activityId,
            'outboundCall' => $outboundCallData,
        ];

        $client = new Client($app, $company);
        $response = $client->post(
            "/sales/v1/elead/activities/{$activityId}/outbound-call",
            $data
        );

        return $response;
    }

    /**
     * Open activities only; eLead caps the fromDate/toDate window at 31 days.
     *
     * @return self[]
     */
    public static function getOpenActivitiesByOpportunityId(
        AppInterface $app,
        Companies $company,
        string $opportunityId,
        ?Carbon $from = null,
        ?Carbon $to = null
    ): array {
        return self::getActivitiesByOpportunityId(
            $app,
            $company,
            $opportunityId,
            $from,
            $to,
            openOnly: true
        );
    }

    /**
     * eLead caps the fromDate/toDate window at 31 days.
     *
     * @return self[]
     */
    public static function getActivitiesByOpportunityId(
        AppInterface $app,
        Companies $company,
        string $opportunityId,
        ?Carbon $from = null,
        ?Carbon $to = null,
        bool $openOnly = false
    ): array {
        $queryParams = $openOnly ? ['openActivitiesOnly' => 'true'] : [];

        if ($from !== null) {
            $queryParams['fromDate'] = $from->copy()->utc()->format('Y-m-d\TH:i:s\Z');
        }

        if ($to !== null) {
            $queryParams['toDate'] = $to->copy()->utc()->format('Y-m-d\TH:i:s\Z');
        }

        $activities = self::getHistoryByOpportunityId(
            $app,
            $company,
            $opportunityId,
            $queryParams
        );

        return array_map(
            fn (array $item) => self::fromResponse($app, $company, $item),
            $activities['items'] ?? []
        );
    }

    public static function hasSalesAgentReachedOut(
        AppInterface $app,
        Companies $company,
        string $opportunityId,
        array $queryParams = []
    ): bool {
        $cache = new EleadCache($app, $company);
        $cached = $cache->get('agent_reached_out', $opportunityId);

        if ($cached !== null) {
            return (bool) $cached['value'];
        }

        $activities = self::getHistoryByOpportunityId(
            $app,
            $company,
            $opportunityId,
            $queryParams
        );

        $result = false;

        foreach ($activities['items'] ?? [] as $activity) {
            $phoneOutReach = $activity['activityType'] === 'Phone Call'
                && $activity['category'] === 'Completed'
                && $activity['name'] === 'Outbound Call';

            $dayOneCall = $activity['activityType'] === 'Phone Call'
                && $activity['name'] === 'Day 1 Call/Text'
                && strtolower($activity['outcome']) === 'contacted';

            $contacted = strtolower($activity['outcome'] ?? '') === 'contacted';

            if ($phoneOutReach || $dayOneCall || $contacted) {
                $result = true;

                break;
            }
        }

        // Cache for 30 min — once contacted, this rarely flips back
        $cache->set(
            'agent_reached_out',
            $opportunityId,
            ['value' => $result],
            1800
        );

        return $result;
    }

    /**
     * Get Lead.
     */
    public function lead(): Lead
    {
        return Lead::getById($this->app, $this->company, $this->opportunityId);
    }
}
