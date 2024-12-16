<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Amplitude;

use Baka\Traits\KanvasJobsTrait;
use DateInterval;
use DatePeriod;
use DateTime;
use Google\Cloud\DiscoveryEngine\V1\Client\UserEventServiceClient;
use Google\Cloud\DiscoveryEngine\V1\DocumentInfo;
use Google\Cloud\DiscoveryEngine\V1\UserEvent;
use Google\Cloud\DiscoveryEngine\V1\WriteUserEventRequest;
use Google\Protobuf\Timestamp;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Amplitude\Client;
use Kanvas\Connectors\Google\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Social\Enums\InteractionEnum;
use Kanvas\Users\Models\UsersAssociatedApps;

class SyncEventsWithGoogleCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:amplitude-sync-events-to-google {app_id} {company_id} {start_date} {end_date}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Sync all events from Amplitude to Google Dynamic Recommendation';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $company = Companies::getById((int) $this->argument('company_id'));
        $amplitudeClient = new Client($app, $company);

        $startDate = new DateTime($this->argument('start_date'));
        $endDate = new DateTime($this->argument('end_date'));

        // Create a DatePeriod object to loop through each day
        $interval = new DateInterval('P1D'); // 1 day interval
        $dateRange = new DatePeriod($startDate, $interval, $endDate->add($interval));

        $googleClientConfig = $app->get(ConfigurationEnum::GOOGLE_CLIENT_CONFIG->value);
        $googleRecommendationConfig = $app->get(ConfigurationEnum::GOOGLE_RECOMMENDATION_CONFIG->value);

        if (! $googleClientConfig) {
            throw new ValidationException('Google client config not found for app ' . $app->name);
        }

        if (! $googleRecommendationConfig) {
            throw new ValidationException('Google recommendation config not found for app ' . $app->name);
        }

        foreach ($dateRange as $date) {
            $currentDate = $date->format('Y-m-d');
            $this->info("Syncing events for {$currentDate}");

            // Fetch events from Amplitude for the current day
            $results = $amplitudeClient->eventsExport($currentDate, $currentDate);

            foreach ($results as $result) {
                $eventDateTime = new DateTime($result['client_event_time']);
                $eventType = $result['event_type'] ?? null;

                $eventTypeWeb = $result['event_properties']['[Amplitude] Page Path'] ?? null;
                $displayname = $result['event_properties']['username'] ?? null;
                $messageId = $result['event_properties']['prompt_id'] ?? 0;

                $eventType = match (trim($eventType)) {
                    'View Explore' => InteractionEnum::VIEW_HOME_PAGE->getValue(),
                    'Page Viewed' => InteractionEnum::VIEW_ITEM->getValue(),
                    'View Library' => InteractionEnum::VIEW_ITEM->getValue(),
                    'Select Prompt' => InteractionEnum::VIEW_ITEM->getValue(),
                    '[Amplitude] Page Viewed' => InteractionEnum::VIEW_ITEM->getValue(),
                    'Clicking Output Icon' => InteractionEnum::VIEW_ITEM->getValue(),
                    'Clicking AI Nugget Preview' => InteractionEnum::VIEW_ITEM->getValue(),
                    default => null,
                };

                if (! $eventType) {
                    continue;
                }

                if (! $messageId) {
                    continue;
                }

                $user = UsersAssociatedApps::query()->fromApp($app)->where('displayname', $displayname)->first();

                if (! $user) {
                    // Use default user if no user found
                    $user = UsersAssociatedApps::query()->fromApp($app)->where('users_id', $app->get('default_user_recommendation_catchall_id'))->first();
                }

                if (! $user) {
                    continue;
                }

                $document = new DocumentInfo();
                $document->setId($messageId);

                $eventTime = new Timestamp();
                $eventTime->fromDateTime($eventDateTime);

                // Prepare the request message
                $userEvent = (new UserEvent())
                    ->setEventType($eventType)
                    ->setUserPseudoId((string) $user->users_id)
                    ->setDocuments([$document])
                    ->setEventTime($eventTime);

                $writeUserEventRequest = (new WriteUserEventRequest())
                    ->setParent(UserEventServiceClient::dataStoreName(
                        $googleRecommendationConfig['projectId'],
                        $googleRecommendationConfig['location'],
                        $googleRecommendationConfig['dataSource']
                    ))
                    ->setUserEvent($userEvent);

                $googleClient = new UserEventServiceClient([
                    'credentials' => $googleClientConfig,
                ]);

                // Send event to Google
                $googleClient->writeUserEvent($writeUserEventRequest);
                $this->info('Event: ' . $eventType . ' for user ' . $user->user->getId() . ' at ' . $eventTime->toDateTime()->format('Y-m-d H:i:s'));
            }
        }

        $this->info('All events synced.');
    }
}
