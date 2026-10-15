<?php

declare(strict_types=1);

namespace Tests\Guild\Customers\Actions;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Actions\SendEmailToPeopleAction;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Neuron\Tools\Templates\CreateTemplateTool;
use Kanvas\Notifications\Templates\Blank;
use Tests\TestCase;

final class SendEmailToPeopleActionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'ecosystem'];

    private function freshPerson(Companies $company): People
    {
        return People::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($company->getId())
            ->withUserId(auth()->user()->getId())
            ->create();
    }

    public function testUsesTheDefaultTemplateWhenNoneIsGiven(): void
    {
        Notification::fake();
        $person = $this->freshPerson(Companies::factory()->create());

        $result = new SendEmailToPeopleAction($person)->execute(to: 'test@example.test', message: 'Hi there');

        $this->assertSame('first-time-agent-engagement', $result['template']);
        Notification::assertSentOnDemand(
            Blank::class,
            fn (Blank $notification): bool => $notification->getTemplateName() === 'first-time-agent-engagement',
        );
    }

    public function testUsesTheGivenTemplateNameInstead(): void
    {
        Notification::fake();
        $app = app(Apps::class);
        $company = Companies::factory()->create();
        $user = auth()->user();
        $person = $this->freshPerson($company);

        $templateName = 'tpl-' . uniqid();
        $created = new CreateTemplateTool()
            ->withContext($app, $company, $user)
            ->__invoke(name: $templateName, html: '<p>{{ $content }}</p>');
        $this->assertTrue($created['success']);

        $result = new SendEmailToPeopleAction($person)->execute(
            to: 'test@example.test',
            message: 'Hi there',
            templateName: $templateName,
        );

        $this->assertSame($templateName, $result['template']);
        Notification::assertSentOnDemand(
            Blank::class,
            fn (Blank $notification): bool => $notification->getTemplateName() === $templateName,
        );
    }

    public function testAttachmentUrlsAreCarriedOnTheNotification(): void
    {
        Notification::fake();
        $person = $this->freshPerson(Companies::factory()->create());

        new SendEmailToPeopleAction($person)->execute(
            to: 'test@example.test',
            message: 'Hi there',
            attachmentUrls: ['https://example.test/a.pdf'],
        );

        Notification::assertSentOnDemand(
            Blank::class,
            fn (Blank $notification): bool => $notification->pathAttachment === ['https://example.test/a.pdf'],
        );
    }

    public function testThrowsWhenThereIsNoDestinationAddress(): void
    {
        $person = $this->freshPerson(Companies::factory()->create());

        $this->expectException(ValidationException::class);

        new SendEmailToPeopleAction($person)->execute(to: '   ', message: 'Hi there');
    }
}
