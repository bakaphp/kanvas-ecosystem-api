<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Amplitude;

use Baka\Traits\KanvasJobsTrait;
use DateTime;
use Google\Cloud\DiscoveryEngine\V1\Client\RecommendationServiceClient;
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
use Kanvas\Social\Messages\Models\Message;
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

        $this->info('Syncing events from Amplitude to Google Dynamic Recommendation for app ' . $app->name . ' during ' . $this->argument('start_date') . ' to ' . $this->argument('end_date'));

        $results = $amplitudeClient->eventsExport($this->argument('start_date'), $this->argument('end_date'));
        $googleClientConfig = $app->get(ConfigurationEnum::GOOGLE_CLIENT_CONFIG->value);
        $googleRecommendationConfig = $app->get(ConfigurationEnum::GOOGLE_RECOMMENDATION_CONFIG->value);

        if (! $googleClientConfig) {
            throw new ValidationException('Google client config not found for app ' . $$app->name);
        }

        if (! $googleRecommendationConfig) {
            throw new ValidationException('Google recommendation config not found for app ' . $$app->name);
        }

        $googleClientConfig = $googleClientConfig;
        $googleRecommendationConfig = $googleRecommendationConfig;
        $client = new RecommendationServiceClient([
            'credentials' => $googleClientConfig,
        ]);
        $userEventServiceClient = UserEventServiceClient::dataStoreName(
            $googleRecommendationConfig['projectId'],
            $googleRecommendationConfig['location'],
            $googleRecommendationConfig['dataSource'],
        );

        foreach ($results as $result) {
            $eventDateTime = new DateTime($result['client_event_time']);
            $eventType = $result['event_type'] ?? null;

            $eventTypeWeb = $result['event_properties']['[Amplitude] Page Path'] ?? null;
            $displayname = $result['event_properties']['username'] ?? null;
            $messageId = $result['event_properties']['prompt_id'] ?? 0;

            $eventType = match (trim($eventType)) {
                'View Explore' => 'view-home-page',
                'Page Viewed' => 'view-item',
                'View Library' => 'view-home-page',
                'Select Prompt' => 'view-item',
                '[Amplitude] Page Viewed' => 'view-item',
                default => null,
            };

            if (! $eventType) {
                continue;
            }

            $user = UsersAssociatedApps::fromApp($app)->where('displayname', $displayname)->first();

            if (! $user) {
                continue;
            } else {
                $user = UsersAssociatedApps::fromApp($app)->where('users_id', $app->get('default_user_recommendation_catchall_id'))->first();
            }

            $document = new DocumentInfo();
            $document->setId($messageId);

            $eventTime = new Timestamp();
            $eventTime->fromDateTime($eventDateTime);

            // Prepare the request message.
            $userEvent = (new UserEvent())
                ->setEventType($eventType)
                ->setUserPseudoId((string) $user->users_id)
                ->setDocuments([$document])
                ->setEventTime($eventTime);

            $writeUserEventRequest = (new WriteUserEventRequest())
                ->setParent($userEventServiceClient)
                ->setUserEvent($userEvent);

            $client = new UserEventServiceClient([
                'credentials' => $googleClientConfig,
            ]);

            $client->writeUserEvent($writeUserEventRequest);
            $this->info('Event: ' . $eventType . ' for user ' . $user->displayname . ' at ' . $eventTime->toDateTime()->format('Y-m-d H:i:s'));
        }
    }
}
