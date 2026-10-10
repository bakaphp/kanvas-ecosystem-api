<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Mailgun;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Mailgun\Actions\SendAgentEmailAction;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Social\Messages\Models\Message;
use ReflectionMethod;
use Tests\TestCase;

final class PeopleEmailThreadAnchorTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'social'];

    public function testThreadsUnderThePersonsOwnAnchor(): void
    {
        $people = $this->makePeople();
        $people->set('title_email_follow_up', 'Your trade-in quote');

        $this->assertSame('Re: Your trade-in quote', $this->resolveSubject($people));
    }

    public function testFallsBackToTheActiveLeadAnchor(): void
    {
        $people = $this->makePeople();
        $lead = Lead::factory()
            ->withAppAndCompany(app(Apps::class)->getId(), $people->companies_id)
            ->create(['people_id' => $people->getId()]);
        $lead->set('title_email_follow_up', 'Re: Test drive Saturday');

        $this->assertSame('Re: Test drive Saturday', $this->resolveSubject($people));
    }

    public function testPersonWithAiModeOffIsMuted(): void
    {
        $people = $this->makePeople();
        $this->assertFalse($people->isAiMuted());

        $people->set('ai_mode', IntelligenceModeEnum::OFF->value);

        $this->assertTrue($people->isAiMuted());
    }

    private function resolveSubject(People $people): string
    {
        $action = new SendAgentEmailAction(Message::factory()->make());

        return new ReflectionMethod($action, 'resolveSubject')->invoke($action, $people, 'reply body');
    }

    private function makePeople(): People
    {
        return People::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
            ->create();
    }
}
