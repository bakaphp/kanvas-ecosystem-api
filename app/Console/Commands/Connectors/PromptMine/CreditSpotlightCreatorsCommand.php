<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\PromptMine;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\PromptMine\Notifications\SpotlightCreatorCreditPushNotification;
use Kanvas\Connectors\Recombee\Services\RecombeeUserRecommendationService;
use Kanvas\Notifications\Enums\NotificationChannelEnum;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;

class CreditSpotlightCreatorsCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:promptmine-credit-spotlight-creators {app_id}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Command to check recommbee for new spotlight creators';

    /**
     * Execute the console command.
     *
     */
    public function handle()
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        // Fetch spotlight creators from recommbee.

        $user = Users::find(-1);
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $currentPage = (int) ($args['page'] ?? 1);
        $recommendationService = new RecombeeUserRecommendationService($app);
        $pageSize = 50;
        $scenario = 'spotlight-feed';

        $response = $recommendationService->getUserCustomScenarioRecommendation(
            user: $user,
            count: $pageSize,
            scenario: $scenario
        );

        // Check for newly added prompts
        $newSpotlightPrompts = $this->checkForNewPrompts($response['recomms'], $app, $company);

        // Verify if a prompt a new creator has been added
        $this->creditSpotlightCreators($newSpotlightPrompts, $app, $company);
    }

    private function checkForNewPrompts(array $recommendations, Apps $app, Companies $company): array
    {
        $newSpotlightPrompts = [];
        foreach ($recommendations as $recommendation) {
            $entityId = $recommendation['id'];
            $spotlightMessages = $app->get('spotlight-creator-messages');
            $message = Message::where('recommbee_id', $entityId)
                ->whereIn('id', $spotlightMessages)
                ->where('companies_id', $company->getId())
                ->first();


            if ($message === null) {
                // New prompt found
                // Log it or store it for further processing
                $this->info("New prompt found with Recomm ID: {$entityId}");

                $app->set(
                    'spotlight-creator-messages',
                    array_merge($spotlightMessages, [$entityId])
                );
                $newSpotlightPrompts[] = $message;
            }
        }

        return $newSpotlightPrompts;
    }

    /**
     * @todo implement actual crediting logic, @kaioken should know how this works.
     */
    private function creditSpotlightCreators(array $newSpotlightPrompts, Apps $app, Companies $company): void
    {
        foreach ($newSpotlightPrompts as $message) {
            $creator = $message->user();

            if (! $creator->get('order_credits')) {
                $creator->set('order_credits', '{"video":{"veo-3.1-fast-generate-preview":1,"veo-3.1-generate-preview":1,"veo-3.1-fast-generate-001":1}}');
                return;
            }

            $creditsArray = json_decode($creator->get('order_credits'), true);
            foreach ($creditsArray as $modelCredit) {
                $modelCredit += 1;
            }

            $this->info("Credited 1 to creator ID: {$creator->getId()} for message ID: {$message->getId()}");

            //Notify the creator about the credit addition
            $this->sendCreatorCreditNotification(
                entity: $message,
                params: [
                    'push_template' => 'spotlight_creator_credit_notification_push',
                ]
            );
        }
    }

    private function sendCreatorCreditNotification(Message $entity, array $params): void
    {
        $endViaList = array_map(
            [NotificationChannelEnum::class, 'getNotificationChannelBySlug'],
            $params['via'] ?? ['database']
        );
        $errorProcessingImageNotification = new SpotlightCreatorCreditPushNotification(
            user: $entity->user,
            entity: $entity,
            message: "Your AI creation was so good, we had to feature it. As a thank you, we've added one free Veo 3 generation credit to your account. Check it out!",
            title: "You're in the Spotlight!",
            via: $endViaList,
            templates: [
                'push_template' => $params['push_template'],
            ],
        );

        //send to the user profile when it fails
        $errorProcessingImageNotification->setData([
            'destination_id' => $entity->getId(),
            'destination_type' => 'USER',
            'destination_event' => 'FOLLOWING',
        ]);
        $entity->user->notify($errorProcessingImageNotification);
    }
}
