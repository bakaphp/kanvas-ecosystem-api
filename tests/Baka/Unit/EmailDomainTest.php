<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use Baka\Validations\EmailDomain;
use Baka\Validations\EmailProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmailDomainTest extends TestCase
{
    /**
     * Verbatim addresses from the bot campaign that produced ~14k accounts.
     * Every one of them is an impossible Gmail address.
     *
     * @return list<array{string}>
     */
    public static function botCampaignEmailProvider(): array
    {
        return [
            ['dggie_l9pbrxc3ex@gmail.com'],
            ['dggie_uyog4qe5k5@gmail.com'],
            ['dggie_machdh584p@gmail.com'],
            ['dggie_4yhh9ugyye@gmail.com'],
            ['dggie_u0wugdwp90@gmail.com'],
            ['dggie_rd3srygaz5@gmail.com'],
            ['dggie_gsiyx687v6@gmail.com'],
            ['dggie_pby9hgz6ci@gmail.com'],
            ['dggie_y958koe8lc@gmail.com'],
            ['dggie_y4kjqiuc9n@gmail.com'],
        ];
    }

    /**
     * @return list<array{string}>
     */
    public static function legitimateEmailProvider(): array
    {
        return [
            ['contact@example-corp.com'],
            ['ana.rivera@gmail.com'],
            ['anarivera1985@gmail.com'],
            ['a.rivera.soto@googlemail.com'],
            ['anarivera+news@gmail.com'],
            ['jsmith@gmail.com'],
            ['mcwhite@gmail.com'],
            ['first_last@company.com'],
            ['maria.rodriguez-perez@outlook.com'],
            ['route66to99@yahoo.com'],
            ['john1985@icloud.com'],
        ];
    }

    #[DataProvider('botCampaignEmailProvider')]
    public function testBotCampaignEmailsAreRejected(string $email): void
    {
        $this->assertNotNull(
            EmailDomain::spamReason($email),
            $email . ' should be rejected as a bot signup.'
        );
    }

    #[DataProvider('legitimateEmailProvider')]
    public function testLegitimateEmailsAreAccepted(string $email): void
    {
        $this->assertNull(
            EmailDomain::spamReason($email),
            $email . ' is a valid address and must not be blocked.'
        );
    }

    public function testUnderscoreIsNotALegalGmailUsername(): void
    {
        $this->assertTrue(EmailProvider::violatesProviderRules('dggie_l9pbrxc3ex@gmail.com'));
        $this->assertTrue(EmailProvider::violatesProviderRules('ana-rivera@gmail.com'));
        $this->assertTrue(EmailProvider::violatesProviderRules('.anarivera@gmail.com'));
        $this->assertTrue(EmailProvider::violatesProviderRules('ana..rivera@gmail.com'));
        $this->assertTrue(EmailProvider::violatesProviderRules('anarivera.@gmail.com'));
        $this->assertTrue(EmailProvider::violatesProviderRules(str_repeat('a', 31) . '@gmail.com'));
    }

    public function testProviderRulesOnlyApplyToKnownProviders(): void
    {
        $this->assertFalse(EmailProvider::violatesProviderRules('first_last@example-corp.com'));
        $this->assertFalse(EmailProvider::violatesProviderRules('first_last@company.co.uk'));
    }

    public function testPlusTagIsStrippedBeforeGmailValidation(): void
    {
        $this->assertFalse(EmailProvider::violatesProviderRules('ana.rivera+kanvas_signup@gmail.com'));
    }

    public function testGmailAliasesCanonicalizeToOneMailbox(): void
    {
        $this->assertSame('anarivera@gmail.com', EmailProvider::canonicalize('a.n.a.rivera@gmail.com'));
        $this->assertSame('anarivera@gmail.com', EmailProvider::canonicalize('anarivera+one@googlemail.com'));
        $this->assertSame('anarivera@gmail.com', EmailProvider::canonicalize('AnaRivera@Gmail.com'));
    }

    public function testUnknownProviderKeepsDotsWhenCanonicalizing(): void
    {
        $this->assertSame('ana.rivera@example-corp.com', EmailProvider::canonicalize('Ana.Rivera@example-corp.com'));
    }

    public function testSeparatorNoLongerDisablesTheRandomnessCheck(): void
    {
        $this->assertTrue(EmailDomain::hasSpamLocalPart('dggie_l9pbrxc3ex@example.com'));
        $this->assertTrue(EmailDomain::hasSpamLocalPart('abcd_8rhpkhzq6sqwcx3@example.com'));
    }

    public function testTrailingAndLeadingDigitsAreNotTreatedAsRandom(): void
    {
        $this->assertFalse(EmailDomain::hasSpamLocalPart('johnsmith1985@example.com'));
        $this->assertFalse(EmailDomain::hasSpamLocalPart('1985johnsmith@example.com'));
        $this->assertFalse(EmailDomain::hasSpamLocalPart('john.smith.1985@example.com'));
    }

    public function testShortLocalPartsAreNeverFlagged(): void
    {
        $this->assertFalse(EmailDomain::hasSpamLocalPart('a1b2c3@example.com'));
        $this->assertFalse(EmailDomain::hasSpamLocalPart('jsmith@example.com'));
    }

    public function testBlockedDomainStillTakesPrecedence(): void
    {
        $this->assertSame('blocked_domain', EmailDomain::spamReason('someone@mailinator.com'));
        $this->assertSame('blocked_domain', EmailDomain::spamReason('someone@custom-spam.dev', ['custom-spam.dev']));
    }

    public function testSpamReasonNamesTheRuleThatFired(): void
    {
        $this->assertSame('impossible_provider_address', EmailDomain::spamReason('dggie_l9pbrxc3ex@gmail.com'));
        $this->assertSame('random_local_part', EmailDomain::spamReason('dggie_l9pbrxc3ex@example.com'));
    }
}
