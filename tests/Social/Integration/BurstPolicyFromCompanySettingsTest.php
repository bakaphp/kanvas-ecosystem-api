<?php

declare(strict_types=1);

namespace Tests\Social\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Communication\Messages\DataTransferObject\InboundMessage;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Twilio\TwilioInbound;
use Kanvas\Social\Messages\DataTransferObject\BurstPolicy;
use Tests\TestCase;

/**
 * Each test tunes a company of its own: a company setting is written to Redis and outlives the
 * transaction, so the shared test company would carry it into every other process.
 */
final class BurstPolicyFromCompanySettingsTest extends TestCase
{
    use DatabaseTransactions;

    public function testTheCompanysSettingsUnderThePrefixAreTheWindows(): void
    {
        $company = Companies::factory()->create();
        $company->set('acme_burst_idle_seconds', 5);
        $company->set('acme_burst_max_seconds', 40);
        $company->set('acme_burst_jitter_seconds', 0);

        $policy = BurstPolicy::fromCompanySettings($company, 'acme', ['acme:1']);

        $this->assertSame(['acme:1'], $policy->correlationKeys);
        $this->assertSame(5, $policy->chainIdleSeconds);
        $this->assertSame(5, $policy->closeIdleSeconds);
        $this->assertSame(40, $policy->maxSeconds);
        $this->assertSame(0, $policy->jitterSeconds, 'a stored 0 is a value, not a missing setting');
    }

    public function testAnUntunedCompanyGetsTheDefaultsItIsGiven(): void
    {
        $company = Companies::factory()->create();

        $whatsApp = BurstPolicy::fromCompanySettings($company, 'acme', ['acme:1']);
        $sms = BurstPolicy::fromCompanySettings(
            company: $company,
            prefix: 'acme',
            correlationKeys: ['acme:1'],
            idleSeconds: 15,
            maxSeconds: 90,
            jitterSeconds: 0,
        );

        $this->assertSame([30, 180, 12], [$whatsApp->chainIdleSeconds, $whatsApp->maxSeconds, $whatsApp->jitterSeconds]);
        $this->assertSame([15, 90, 0], [$sms->chainIdleSeconds, $sms->maxSeconds, $sms->jitterSeconds]);
    }

    public function testATextWindowTunedOnTheOldSettingIsKeptUntilTheNewOneIsSet(): void
    {
        $company = Companies::factory()->create();
        $inbound = new InboundMessage(
            provider: 'twilio',
            channel: ChannelTypeEnum::SMS,
            inboundIdentity: '+18095550950',
            from: '+18095550199',
        );

        $this->assertSame(15, new TwilioInbound()->burstPolicy($inbound, $company)->chainIdleSeconds);

        $company->set('twilio_batch_delay_seconds', 7);
        $this->assertSame(7, new TwilioInbound()->burstPolicy($inbound, $company)->chainIdleSeconds);

        $company->set('twilio_burst_idle_seconds', 20);
        $policy = new TwilioInbound()->burstPolicy($inbound, $company);

        $this->assertSame(20, $policy->chainIdleSeconds);
        $this->assertSame(['sms:8095550199'], $policy->correlationKeys);
        $this->assertSame([90, 0], [$policy->maxSeconds, $policy->jitterSeconds]);
    }
}
