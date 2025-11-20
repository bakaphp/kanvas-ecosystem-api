<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Entities;

use Baka\Contracts\AppInterface;
use DateTime;
use InvalidArgumentException;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Elead\Client;

class SalesActivities
{
    public ?string $activityId = null;
    public ?string $opportunityId = null;
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

        $newLead = new self();
        $newLead->company = $company;
        $newLead->app = $app;
        $newLead->assign($response);

        return $newLead;
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

        $newLead = new self();
        $newLead->company = $company;
        $newLead->app = $app;
        $newLead->assign($response);

        return $newLead;
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
    public static function getActivityTypes(AppInterface $app, Companies $company): array
    {
        $client = new Client($app, $company);
        $response = $client->get('/sales/v1/elead/activities/types');

        return $response;
    }

    /**
     * Get activity outcomes.
     */
    public static function getActivityOutcomes(AppInterface $app, Companies $company): array
    {
        $client = new Client($app, $company);
        $response = $client->get('/sales/v1/elead/activities/outcomes');

        return $response;
    }

    public static function getActivityOutcome(AppInterface $app, Companies $company, string $activityId): array
    {
        $client = new Client($app, $company);
        $response = $client->get("/sales/v1/elead/activities/{$activityId}/outcomes");

        return $response;
    }

    /**
     * Get activity by activity ID.
     */
    public static function getById(AppInterface $app, Companies $company, string $activityId): self
    {
        $client = new Client($app, $company);
        $response = $client->get("/sales/v1/elead/activities/{$activityId}");

        $activity = new self();
        $activity->company = $company;
        $activity->app = $app;
        $activity->assign($response);

        return $activity;
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
     * Get activity history with search (includes both scheduled and completed activities).
     * Retrieve activity history with comments by sales customer and opportunity identifier.
     */
    public static function getHistorySearch(
        AppInterface $app,
        Companies $company,
        string $customerId,
        string $opportunityId
    ): array {
        $client = new Client($app, $company);
        $url = '/sales/v1/elead/activity-history/search';
        $queryParams = [
            'customerId' => $customerId,
            'opportunityId' => $opportunityId,
        ];

        $url .= '?' . http_build_query($queryParams);

        return $client->get($url);
    }

    /**
     * Update an existing activity.
     */
    public function update(array $updateData): array
    {
        if (! $this->activityId) {
            throw new \InvalidArgumentException('Activity ID is required to update an activity');
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

        $activity = new self();
        $activity->company = $company;
        $activity->app = $app;
        $activity->assign($response);

        return $activity;
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

    public static function getOpenActivitiesByOpportunityId(
        AppInterface $app,
        Companies $company,
        string $opportunityId,
        array $queryParams = []
    ): array {
        $activities = self::getHistoryByOpportunityId($app, $company, $opportunityId, $queryParams);

        $openActivities = [];

        if (isset($activities['items'])) {
            foreach ($activities['items'] as $activity) {
                if (isset($activity['outcome']) && $activity['outcome'] !== 'Completed') {
                    $openActivities[] = $activity;
                }
            }
        }

        return [
            'items' => $openActivities,
            'links' => $activities['links'] ?? [],
        ];
    }

    /**
     * Get Lead.
     */
    public function lead(): Lead
    {
        return Lead::getById($this->app, $this->company, $this->opportunityId);
    }
}
