<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Contracts\RequiresSystemAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SendPersonEmailTool;
use Kanvas\NervousSystem\Plan\Support\WorkerToolPolicy;
use Kanvas\Notifications\Templates\Blank;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Serial: one case sets the CC domain allowlist, an app setting that lives in Redis shared by every
 * paratest process.
 */
#[Group('serial')]
final class SendPersonEmailToolTest extends TestCase
{
    protected function tearDown(): void
    {
        app(Apps::class)->del(ConfigurationEnum::AGENT_EMAIL_CC_ALLOWED_DOMAINS->value);

        parent::tearDown();
    }

    private function person(?string $email = 'person@example.com'): People
    {
        $person = People::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
            ->create();
        $person->contacts()->delete();

        if ($email !== null) {
            $person->addEmail($email);
        }

        return $person;
    }

    private function send(People $person, ?string $cc = null): array
    {
        $user = auth()->user();

        return new SendPersonEmailTool()
            ->withContext(app(Apps::class), $user->getCurrentCompany(), $user)
            ->__invoke(
                person_id: $person->getId(),
                subject: 'Welcome',
                body: 'Welcome aboard.',
                cc: $cc,
            );
    }

    public function testEmailsAPersonWithNoLeadAtTheAddressOnFile(): void
    {
        Notification::fake();
        $person = $this->person();

        $result = $this->send($person);

        $this->assertTrue($result['success']);
        $this->assertSame('person@example.com', $result['to']);
        $this->assertSame([], $result['cc']);
        Notification::assertSentOnDemand(
            Blank::class,
            fn (Blank $notification, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === 'person@example.com'
        );
    }

    public function testCopiesAnotherContactOfThePersonAndOfTheirOrganization(): void
    {
        Notification::fake();
        $person = $this->person();
        $person->addEmail('personal@example.com');

        $organization = Organization::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => auth()->user()->getCurrentCompany()->getId(),
            'users_id' => auth()->user()->getId(),
            'name' => 'Acme',
            'address' => '',
            'total_employees' => 0,
        ]);
        $colleague = $this->person('colleague@example.com');
        $person->organizations()->attach($organization->getId(), ['created_at' => now()]);
        $colleague->organizations()->attach($organization->getId(), ['created_at' => now()]);

        $result = $this->send($person, 'personal@example.com, colleague@example.com');

        $this->assertSame(['personal@example.com', 'colleague@example.com'], $result['cc']);
        $this->assertSame([], $result['cc_rejected']);
        Notification::assertSentOnDemand(
            Blank::class,
            fn (Blank $notification): bool => $notification->getCc() === ['personal@example.com', 'colleague@example.com']
        );
    }

    public function testRejectsAnUnrelatedAddressAndSaysNotToSendItAnotherWay(): void
    {
        Notification::fake();
        $person = $this->person();

        $result = $this->send($person, 'someone@gmail.com');

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['cc']);
        $this->assertSame(['someone@gmail.com'], $result['cc_rejected']);
        $this->assertStringContainsString('Do NOT send them a copy any other way', $result['note']);
    }

    public function testCopiesAnAddressOnAnAllowedDomain(): void
    {
        Notification::fake();
        app(Apps::class)->set(ConfigurationEnum::AGENT_EMAIL_CC_ALLOWED_DOMAINS->value, 'person-cc.kanvas.test');
        $person = $this->person();

        $result = $this->send($person, 'teammate@person-cc.kanvas.test');

        $this->assertSame(['teammate@person-cc.kanvas.test'], $result['cc']);
    }

    public function testRefusesAPersonMarkedDoNotContact(): void
    {
        Notification::fake();
        $person = $this->person();
        $person->set('do_not_contact', 1);

        $result = $this->send($person);

        $this->assertFalse($result['success']);
        $this->assertSame('denied', $result['outcome']);
        Notification::assertNothingSent();
    }

    public function testAPersonWithNoEmailIsToldToSaveOne(): void
    {
        Notification::fake();
        $person = $this->person(null);

        $result = $this->send($person);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('manage_person_contact', $result['error']);
        Notification::assertNothingSent();
    }

    public function testAHallucinatedPersonIdSendsNothing(): void
    {
        Notification::fake();
        $user = auth()->user();

        $result = new SendPersonEmailTool()
            ->withContext(app(Apps::class), $user->getCurrentCompany(), $user)
            ->__invoke(person_id: 999999999, subject: 'Welcome', body: 'Welcome aboard.');

        $this->assertArrayHasKey('error', $result);
        Notification::assertNothingSent();
    }

    public function testIsInternalOnlyAndDeniedToUnsupervisedWorkers(): void
    {
        $this->assertInstanceOf(RequiresSystemAgent::class, new SendPersonEmailTool());
        $this->assertFalse(WorkerToolPolicy::permits('send_person_email'));
    }
}
