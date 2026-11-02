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
use Kanvas\Notifications\Support\UserEmailSignature;
use Kanvas\Notifications\Templates\Blank;
use Tests\TestCase;
use Tests\Traits\MakesAgents;

final class SendEmailToPeopleActionTest extends TestCase
{
    use DatabaseTransactions;
    use MakesAgents;

    protected array $connectionsToTransact = ['mysql', 'crm', 'ecosystem', 'intelligence'];

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

    public function testMarksTheEmailImportantOnlyWhenRequested(): void
    {
        Notification::fake();
        $person = $this->freshPerson(Companies::factory()->create());

        new SendEmailToPeopleAction($person)->execute(to: 'test@example.test', message: 'Hi there', important: true);

        Notification::assertSentOnDemand(
            Blank::class,
            fn (Blank $notification): bool => $notification->isImportant(),
        );
    }

    public function testIsNotImportantByDefault(): void
    {
        Notification::fake();
        $person = $this->freshPerson(Companies::factory()->create());

        new SendEmailToPeopleAction($person)->execute(to: 'test@example.test', message: 'Hi there');

        Notification::assertSentOnDemand(
            Blank::class,
            fn (Blank $notification): bool => ! $notification->isImportant(),
        );
    }

    public function testAnAgentGrantedSigningSignsWithTheOwnersSignatureForThePeoplesCompany(): void
    {
        $this->assertStringContainsString('Company Owner', (string) $this->sentSignature(agentSigns: true));
    }

    public function testAnAgentWithoutTheSigningGrantSendsUnsignedEvenWhenTheOwnerHasOne(): void
    {
        $this->assertNull($this->sentSignature(agentSigns: false));
    }

    public function testNoSignatureOwnerSendsUnsigned(): void
    {
        Notification::fake();
        $person = $this->freshPerson(Companies::factory()->create());

        new SendEmailToPeopleAction($person)->execute(to: 'test@example.test', message: 'Hi there');

        Notification::assertSentOnDemand(
            Blank::class,
            fn (Blank $notification): bool => ! isset($notification->getData()['user_signature']),
        );
    }

    public function testThrowsWhenThereIsNoDestinationAddress(): void
    {
        $person = $this->freshPerson(Companies::factory()->create());

        $this->expectException(ValidationException::class);

        new SendEmailToPeopleAction($person)->execute(to: '   ', message: 'Hi there');
    }

    /**
     * @return string|null the user signature the email carried, null when unsigned
     */
    private function sentSignature(bool $agentSigns): ?string
    {
        Notification::fake();
        $company = Companies::factory()->create();
        $owner = auth()->user();
        $owner->set(UserEmailSignature::companySettingKey($company), ['append' => true, 'text' => "Company Owner\nDirector"]);

        try {
            new SendEmailToPeopleAction($this->freshPerson($company))->execute(
                to: 'test@example.test',
                message: 'Hi there',
                fromAgent: $this->makeSigningAgent($company, $agentSigns),
                signatureOwner: $owner,
            );
        } finally {
            $owner->del(UserEmailSignature::companySettingKey($company));
        }

        $signature = null;
        Notification::assertSentOnDemand(
            Blank::class,
            function (Blank $notification) use (&$signature): bool {
                $signature = $notification->getData()['user_signature'] ?? null;

                return true;
            }
        );

        return $signature;
    }
}
