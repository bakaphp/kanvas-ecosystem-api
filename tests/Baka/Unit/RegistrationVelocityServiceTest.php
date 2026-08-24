<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

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
}
