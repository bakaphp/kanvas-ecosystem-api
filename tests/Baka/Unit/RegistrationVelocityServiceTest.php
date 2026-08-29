<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use Illuminate\Support\Carbon;
use Kanvas\Auth\Services\RegistrationVelocityService;
use Tests\Stubs\Auth\InMemorySettingsApp;
use Tests\TestCase;

class RegistrationVelocityServiceTest extends TestCase
{
    /**
     * The counters live in the default cache store, which is shared with the
     * rest of the environment — pin them to the in-memory store so a test run
     * neither reads nor evicts real signup buckets.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function service(array $settings = [], int $appId = 1): RegistrationVelocityService
    {
        return new RegistrationVelocityService(InMemorySettingsApp::withSettings($settings, $appId));
    }

    public function testPrefixBurstIsBlockedAfterTheDefaultLimit(): void
    {
        $service = $this->service();
        $emails = [
            'dggie_l9pbrxc3ex@example.com',
            'dggie_uyog4qe5k5@example.com',
            'dggie_machdh584p@example.com',
            'dggie_4yhh9ugyye@example.com',
            'dggie_u0wugdwp90@example.com',
        ];

        foreach ($emails as $email) {
            $this->assertNull($service->violation($email), $email . ' is within the burst limit.');
        }

        $this->assertSame('local_part_prefix_burst', $service->violation('dggie_rd3srygaz5@example.com'));
    }

    public function testUnrelatedPrefixesDoNotShareABucket(): void
    {
        $service = $this->service();

        foreach (['dggie_a1@example.com', 'dggie_b2@example.com', 'dggie_c3@example.com'] as $email) {
            $service->violation($email);
        }

        $this->assertNull($service->violation('beatriz.solis@example.com'));
        $this->assertNull($service->violation('roberto.perez@example.com'));
    }

    public function testGmailAliasesOfOneMailboxTripTheMailboxLimit(): void
    {
        $service = $this->service();

        $this->assertNull($service->violation('anarivera@gmail.com'));
        $this->assertNull($service->violation('ana.rivera@gmail.com'));
        $this->assertNull($service->violation('anarivera+one@googlemail.com'));

        $this->assertSame('mailbox_reuse', $service->violation('a.n.a.r.i.v.e.r.a+two@gmail.com'));
    }

    public function testCountersAreScopedPerApp(): void
    {
        $service = $this->service();

        for ($i = 0; $i < 6; $i++) {
            $service->violation('dggie_sample' . $i . '@example.com');
        }

        $this->assertSame('local_part_prefix_burst', $service->violation('dggie_final@example.com'));
        $this->assertNull($this->service(appId: 2)->violation('dggie_final@example.com'));
    }

    public function testTheBurstLimitCanBeTightenedFromAppSettings(): void
    {
        $service = $this->service(['signup_prefix_burst_limit' => 2]);

        $this->assertNull($service->violation('dggie_one@example.com'));
        $this->assertNull($service->violation('dggie_two@example.com'));
        $this->assertSame('local_part_prefix_burst', $service->violation('dggie_three@example.com'));
    }

    public function testAZeroLimitDisablesTheRule(): void
    {
        $service = $this->service([
            'signup_prefix_burst_limit' => 0,
            'signup_mailbox_limit' => 0,
        ]);

        for ($i = 0; $i < 20; $i++) {
            $this->assertNull($service->violation('dggie_sample' . $i . '@gmail.com'));
        }
    }

    /**
     * The `nooowayy@whothefuckru.com` campaign: one signup every ten minutes,
     * which the old fixed window expired between rather than counted.
     */
    public function testASlowDripNoLongerOutlastsTheBurstWindow(): void
    {
        Carbon::setTestNow('2026-08-28 10:00:00');
        $service = $this->service(['signup_prefix_burst_window' => 3600]);

        for ($i = 0; $i < 5; $i++) {
            $this->assertNull($service->violation('nooowayy' . $i . '@example.com'));
            Carbon::setTestNow(Carbon::now()->addMinutes(10));
        }

        $this->assertSame('local_part_prefix_burst', $service->violation('nooowayyfinal@example.com'));
    }

    public function testTheDomainRuleStaysOffUntilAnAppOptsIn(): void
    {
        $service = $this->service([
            'signup_prefix_burst_limit' => 0,
            'signup_mailbox_limit' => 0,
        ]);

        for ($i = 0; $i < 20; $i++) {
            $this->assertNull($service->violation('person' . $i . '@gmail.com'));
        }
    }

    public function testTheDomainBurstFiresOnceAnAppSetsALimit(): void
    {
        $service = $this->service([
            'signup_prefix_burst_limit' => 0,
            'signup_mailbox_limit' => 0,
            'signup_domain_limit' => 3,
        ]);

        foreach (['ana', 'beto', 'carla'] as $local) {
            $this->assertNull($service->violation($local . '@throwaway.test'));
        }

        $this->assertSame('email_domain_burst', $service->violation('dario@throwaway.test'));
    }

    /**
     * The shape the rule cannot see, kept here so nobody mistakes it for cover
     * it does not give: one signup per domain across a provider's vanity
     * catalog never concentrates on a domain, so every counter stays at 1.
     */
    public function testASignupPerDomainStaysUnderTheDomainRule(): void
    {
        $service = $this->service([
            'signup_prefix_burst_limit' => 0,
            'signup_mailbox_limit' => 0,
            'signup_domain_limit' => 3,
        ]);

        $emails = [
            'benjamin.03@gmx.fr',
            'alexander-lucas422@alice.it',
            'luke-2009@email.com',
            'michael03@myself.com',
            'jessica528@doctor.com',
            'james95@analyst.com',
            'ethan02.garcia@pm.me',
            'reed839@seznam.cz',
        ];

        foreach ($emails as $email) {
            $this->assertNull($service->violation($email), $email . ' slips past every velocity rule.');
        }
    }
}
