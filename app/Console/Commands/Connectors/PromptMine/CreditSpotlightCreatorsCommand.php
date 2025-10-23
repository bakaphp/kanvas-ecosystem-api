<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\PromptMine;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Recombee\Services\RecombeeUserRecommendationService;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Social\Tags\Models\Tag;

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

        $user = auth()->user();
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

        return 0;
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
                $creator = $message->getUser();
                $creditAmount = 10;

                // Assuming there's a method to credit the user
                $creator->creditAccount($creditAmount);

                $this->info("Credited {$creditAmount} to creator ID: {$creator->getId()} for message ID: {$message->getId()}");
        }
    }
}
