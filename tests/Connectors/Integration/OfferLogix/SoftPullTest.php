<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\OfferLogix;

use Baka\Support\Str;
use Kanvas\ActionEngine\Engagements\DataTransferObject\EngagementMessage;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\OfferLogix\Actions\SoftPullAction;
use Kanvas\Connectors\OfferLogix\DataTransferObject\SoftPull;
use Kanvas\Connectors\OfferLogix\Enums\ConfigurationEnum as EnumsConfigurationEnum;
use Kanvas\Connectors\OfferLogix\Support\Setup;
use Kanvas\Connectors\OfferLogix\Workflow\SoftPullActivity;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\TestCase;

final class SoftPullTest extends TestCase
{
    public function testSoftPull(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $company->set(EnumsConfigurationEnum::COMPANY_SOURCE_ID->value, getenv('TEST_OFFER_LOGIX_SOURCE_ID'));

        $lead = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();

        $softPullAction = new SoftPullAction($lead, $lead->people);
        $result = $softPullAction->execute(new SoftPull(
            $lead->people->firstname,
            $lead->people->lastname,
            '4444',
            'Atlanta',
            'GA',
            '30308',
        ));

        $this->assertNotNull($result);
        $this->assertTrue(filter_var($result, FILTER_VALIDATE_URL) !== false);
    }

    public function testActivity(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $company->set(EnumsConfigurationEnum::COMPANY_SOURCE_ID->value, getenv('TEST_OFFER_LOGIX_SOURCE_ID'));

        $activity = new SoftPullActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );

        $lead = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();

        $setup = new Setup($app);
        $setup->run();

        $engagementMessage = new EngagementMessage(
            data: [
                [
                    'label' => 'First Name',
                    'value' => 'Mark',
                ],
                [
                    'label' => 'Middle Name',
                    'value' => 'test',
                ],
                [
                    'label' => 'Last Name',
                    'value' => 'Castro',
                ],
                [
                    'label' => 'Mobile',
                    'value' => '8093556261',
                ],
                [
                    'label' => 'State',
                    'value' => '3610',
                ],
                [
                    'label' => 'City',
                    'value' => 'Oklahoma',
                ],
                [
                    'label' => 'Birthday',
                    'value' => '10/21/1997',
                ],
                [
                    'label' => 'Last 4 Digits of SSN',
                    'value' => '4444',
                ],
            ],
            text: 'Soft Pull',
            verb: 'soft-pull',
            hashtagVisited: 'soft-pull',
            actionLink: 'http://localhost:3000/soft-pull?actions_slug=soft-pull',
            source: 'action-age',
            linkPreview: 'http://localhost:3000/soft-pull?actions_slug=soft-pull',
            engagementStatus: 'submitted',
            visitorId: Str::uuid()->toString(),
            status: 'submitted'
        );

        $createMessage = new CreateMessageAction(
            MessageInput::fromArray(
                [
                    'message' => $engagementMessage->toArray(),
                    'reactions_count' => 0,
                    'comments_count' => 0,
                    'total_liked' => 0,
                    'total_disliked' => 0,
                    'total_saved' => 0,
                    'total_shared' => 0,
                    'ip_address' => '127.0.0.1',
                ],
                $user,
                MessageType::fromApp($app)->where('verb', EnumsConfigurationEnum::ACTION_VERB->value)->firstOrFail(),
                $company,
                $app
            ),
            SystemModulesRepository::getByModelName(Lead::class, $app),
            $lead->getId()
        );

        $message = $createMessage->execute();

        /**
         * @todo create engagement
         */
        //$result = $activity->execute($message, $app, ['company' => $company]);

        $this->assertInstanceOf(Message::class, $message);
    }
}
