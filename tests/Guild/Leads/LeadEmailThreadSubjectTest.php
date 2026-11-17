<?php

declare(strict_types=1);

namespace Tests\Guild\Leads;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\TestCase;

/**
 * Every email of a lead threads under one subject: the first touch names it, nothing renames it.
 */
final class LeadEmailThreadSubjectTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm'];

    public function testTheFirstSubjectAnchorsTheThreadAndALaterOneDoesNotRenameIt(): void
    {
        $lead = $this->lead();
        $this->assertNull($lead->emailThreadSubject());
        $this->assertNull($lead->emailThreadReplySubject());

        $lead->anchorEmailThread('  Your quote  ');
        $lead->anchorEmailThread('A second touch');

        $this->assertSame('Your quote', $lead->emailThreadSubject());
        $this->assertSame('Re: Your quote', $lead->emailThreadReplySubject());
    }

    public function testABlankSubjectNeitherAnchorsNorClearsTheThread(): void
    {
        $lead = $this->lead();

        $lead->anchorEmailThread(null);
        $lead->anchorEmailThread('   ');
        $this->assertNull($lead->emailThreadSubject());

        $lead->anchorEmailThread('Your quote');
        $lead->anchorEmailThread(null);
        $this->assertSame('Your quote', $lead->emailThreadSubject());
    }

    public function testAReplySubjectIsNeverPrefixedTwice(): void
    {
        $lead = $this->lead();
        $lead->anchorEmailThread('RE: Availability');

        $this->assertSame('RE: Availability', $lead->emailThreadReplySubject());
    }

    public function testAStoredBlankAnchorReadsAsNoThread(): void
    {
        $lead = $this->lead();
        $lead->set(ConfigurationEnum::EMAIL_THREAD_SUBJECT->value, '');

        $this->assertNull($lead->emailThreadSubject());

        $lead->anchorEmailThread('Your quote');
        $this->assertSame('Your quote', $lead->emailThreadSubject());
    }

    private function lead(): Lead
    {
        return Lead::factory()
            ->withAppAndCompany(app(Apps::class)->getId(), auth()->user()->getCurrentCompany()->getId())
            ->create();
    }
}
