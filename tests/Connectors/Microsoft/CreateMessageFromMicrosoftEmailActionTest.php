<?php

declare(strict_types=1);

namespace Tests\Connectors\Microsoft;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Microsoft\Actions\CreateMessageFromMicrosoftEmailAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\Social\Channels\Models\Channel;
use Tests\TestCase;

class CreateMessageFromMicrosoftEmailActionTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'social', 'crm', 'workflow', 'ecosystem'];

    public function testInboundEmailIsFiledWhenTheLeadHasNoEmailOfItsOwn(): void
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $email = 'customer-' . uniqid() . '@example.com';

        $lead = Lead::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->create();
        $lead->email = null;
        $lead->saveQuietly();

        $message = new CreateMessageFromMicrosoftEmailAction(
            $app,
            $company,
            $user,
            [
                'id' => 'graph-' . uniqid(),
                'conversationId' => 'conversation-' . uniqid(),
                'subject' => 'Re: Quote',
                'from' => ['emailAddress' => ['address' => $email]],
                'body' => ['content' => '<p>Did you see this?</p>'],
            ],
            $lead
        )->execute();

        $channel = Channel::where('entity_id', $lead->getId())
            ->where('entity_namespace', Lead::class)
            ->where('slug', SessionChannelService::createChannelSlug('email', $email))
            ->first();

        $this->assertNotNull($channel);
        $this->assertTrue($channel->messages()->where('messages.id', $message->getId())->exists());
    }
}
