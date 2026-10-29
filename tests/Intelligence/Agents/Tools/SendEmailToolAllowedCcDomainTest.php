<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SendEmailTool;
use Kanvas\Notifications\Templates\Blank;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Serial: the allowlist is an app setting, which lives in Redis shared by every paratest process.
 */
#[Group('serial')]
final class SendEmailToolAllowedCcDomainTest extends TestCase
{
    private const string DOMAIN = 'cc-allowlist.kanvas.test';

    protected function tearDown(): void
    {
        app(Apps::class)->del(ConfigurationEnum::AGENT_EMAIL_CC_ALLOWED_DOMAINS->value);

        parent::tearDown();
    }

    private function allowDomains(mixed $domains): void
    {
        app(Apps::class)->set(ConfigurationEnum::AGENT_EMAIL_CC_ALLOWED_DOMAINS->value, $domains);
    }

    private function makeLead(): Lead
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $lead = Lead::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        $lead->people->contacts()
            ->whereIn('contacts_types_id', [
                ContactTypeEnum::PRIMARY_EMAIL->value,
                ContactTypeEnum::EMAIL->value,
                ContactTypeEnum::SECONDARY_EMAIL->value,
            ])
            ->delete();
        $lead->people->addEmail('prospect@example.com');

        return $lead;
    }

    private function sendWithCc(Lead $lead, string $cc): array
    {
        $user = auth()->user();

        return new SendEmailTool()
            ->withContext(app(Apps::class), $user->getCurrentCompany(), $user)
            ->__invoke(
                lead_id: $lead->getId(),
                subject: 'Your quote',
                body: 'Here is the quote.',
                cc: $cc,
            );
    }

    private function personWithEmail(string $email): People
    {
        $app = app(Apps::class);

        $person = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
            ->create();
        $person->contacts()->delete();
        $person->addEmail($email);

        return $person;
    }

    public function testCopiesAnAddressOnAnAllowedDomainThatIsNotOnTheLead(): void
    {
        Notification::fake();
        $this->allowDomains(self::DOMAIN);
        $lead = $this->makeLead();

        $result = $this->sendWithCc($lead, 'Manager@' . strtoupper(self::DOMAIN));

        $this->assertSame('success', $result['status']);
        $this->assertSame(['Manager@' . strtoupper(self::DOMAIN)], $result['cc']);
        $this->assertSame([], $result['cc_rejected']);
        Notification::assertSentOnDemand(
            Blank::class,
            fn (Blank $notification): bool => $notification->getCc() === ['Manager@' . strtoupper(self::DOMAIN)]
        );
    }

    public function testAcceptsTheListAsAJsonArrayOrWithALeadingAt(): void
    {
        Notification::fake();
        $this->allowDomains(json_encode(['other.test', '@' . self::DOMAIN]));
        $lead = $this->makeLead();

        $result = $this->sendWithCc($lead, 'sales@' . self::DOMAIN);

        $this->assertSame(['sales@' . self::DOMAIN], $result['cc']);
    }

    public function testLookalikeAndSubdomainAddressesAreRejected(): void
    {
        Notification::fake();
        $this->allowDomains(self::DOMAIN);
        $lead = $this->makeLead();

        $rejected = [
            'a@' . self::DOMAIN . '.evil.io',
            'b@evil' . self::DOMAIN,
            'c@sub.' . self::DOMAIN,
        ];
        $result = $this->sendWithCc($lead, implode(',', $rejected));

        $this->assertSame([], $result['cc']);
        $this->assertSame($rejected, $result['cc_rejected']);
    }

    public function testWithoutTheSettingAnUnknownAddressIsStillRejected(): void
    {
        Notification::fake();
        $lead = $this->makeLead();

        $result = $this->sendWithCc($lead, 'manager@' . self::DOMAIN);

        $this->assertSame([], $result['cc']);
        $this->assertSame(['manager@' . self::DOMAIN], $result['cc_rejected']);
    }

    /**
     * An agent told only "not copied" emailed the rejected address separately through
     * send_email_to_user, so the refusal has to rule that out in so many words.
     */
    public function testARejectedCcTellsTheAgentNotToSendACopyAnotherWay(): void
    {
        Notification::fake();
        $lead = $this->makeLead();

        $result = $this->sendWithCc($lead, 'someone@gmail.com');

        $this->assertSame(['someone@gmail.com'], $result['cc_rejected']);
        $this->assertStringContainsString('Do NOT send them a copy any other way', $result['note']);
        $this->assertStringContainsString('send_email_to_user', $result['note']);
    }

    public function testAnAllowedDomainAddressOfAPersonMarkedDoNotContactIsRejected(): void
    {
        Notification::fake();
        $this->allowDomains(self::DOMAIN);
        $lead = $this->makeLead();
        $this->personWithEmail('stopped@' . self::DOMAIN)->set('do_not_contact', 1);

        $result = $this->sendWithCc($lead, 'stopped@' . self::DOMAIN);

        $this->assertSame([], $result['cc']);
        $this->assertSame(['stopped@' . self::DOMAIN], $result['cc_rejected']);
    }

    public function testAnAllowedDomainAddressThatHardBouncedIsRejected(): void
    {
        Notification::fake();
        $this->allowDomains(self::DOMAIN);
        $lead = $this->makeLead();
        $this->personWithEmail('bounced@' . self::DOMAIN)
            ->contacts()
            ->first()
            ->markBounce(permanent: true);

        $result = $this->sendWithCc($lead, 'bounced@' . self::DOMAIN);

        $this->assertSame([], $result['cc']);
        $this->assertSame(['bounced@' . self::DOMAIN], $result['cc_rejected']);
    }
}
