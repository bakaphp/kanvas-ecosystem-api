<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Google\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Google\Cloud\DiscoveryEngine\V1\Client\RecommendationServiceClient;
use Google\Cloud\DiscoveryEngine\V1\Client\UserEventServiceClient;
use Google\Cloud\DiscoveryEngine\V1\UserEvent;
use Google\Cloud\DiscoveryEngine\V1\WriteUserEventRequest;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Social\Interactions\Models\UsersInteractions;
use Kanvas\Social\Messages\Models\Message;

class DiscoveryEngineUserEventService
{
    protected RecommendationServiceClient $client;
    protected array $googleClientConfig;
    protected array $googleRecommendationConfig;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        $googleClientConfig = $this->app->get('google-client-config');
        $googleRecommendationConfig = $this->app->get('google-recommendation-config');

        if (! $googleClientConfig) {
            throw new ValidationException('Google client config not found for app ' . $this->app->name);
        }

        if (! $googleRecommendationConfig) {
            throw new ValidationException('Google recommendation config not found for app ' . $this->app->name);
        }

        $this->googleClientConfig = $googleClientConfig;
        $this->googleRecommendationConfig = $googleRecommendationConfig;
        $this->client = new RecommendationServiceClient([
            'credentials' => $this->googleClientConfig,
        ]);
    }

    public function createUserEvent(UsersInteractions $userInteraction)
    {
        $UserEventServiceClient = UserEventServiceClient::dataStoreName(
            $this->googleRecommendationConfig['projectId'],
            $this->googleRecommendationConfig['location'],
            $this->googleRecommendationConfig['dataSource'],
        );

        /**
         * setEventType , types from google
         *
         * - `search`: Search for Documents.
         * - `view-item`: Detailed page view of a Document.
         * - `view-item-list`: View of a panel or ordered list of Documents.
         * - `view-home-page`: View of the home page.
         * - `view-category-page`: View of a category page, e.g., Home > Men > Jeans.
         *
         * Retail-related values:
         *
         * - `add-to-cart`: Add item(s) to cart, e.g., in retail online shopping.
         * - `purchase`: Purchase item(s).
         *
         * Media-related values:
         *
         * - `media-play`: Start/resume watching a video, playing a song, etc.
         * - `media-complete`: Finished or stopped midway through a video, song, etc.
         */

        // Prepare the request message.
        $userEvent = (new UserEvent())
            ->setEventType($userInteraction->interaction->name)
            ->setUserPseudoId($userInteraction->users_id);

        $request = (new WriteUserEventRequest())
            ->setParent($UserEventServiceClient)
            ->setUserEvent($userEvent);
    }
}
