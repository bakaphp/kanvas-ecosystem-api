<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Services;

use Baka\Contracts\AppInterface;
use DateTime;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Elead\Client;

class SalesActivities
{
    public string $activityId;
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
     * Get Lead.
     */
    public function lead(): Lead
    {
        return Lead::getById($this->app, $this->company, $this->opportunityId);
    }
}
