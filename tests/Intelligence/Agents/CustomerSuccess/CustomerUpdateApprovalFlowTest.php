<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\CustomerSuccess;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Actions\CustomerSuccess\DraftCustomerUpdateAction;
use Kanvas\Intelligence\Agents\Actions\CustomerSuccess\RequestCustomerUpdateApprovalAction;
use Kanvas\Intelligence\Agents\Approvals\CustomerUpdateApprovalHandler;
use Kanvas\Intelligence\Agents\DataTransferObject\CustomerUpdateDraft;
use Kanvas\Notifications\KanvasMailable;
use Kanvas\Social\Messages\Actions\ApproveAgentMessageAction;
use Kanvas\Social\Messages\Support\MessageApproval;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

/**
 * The monthly update must not be able to reach a customer without a human saying so. These pin the two
 * halves: requesting produces a locked private card and mails nothing, approving mails it and only then
 * records that the account has been told.
 */
final class CustomerUpdateApprovalFlowTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'crm', 'social', 'ecosystem', 'intelligence', 'workflow'];

    private const string RECIPIENT = 'customer@example.com';

    public function testRequestingApprovalPostsALockedPrivateCardAndSendsNothing(): void
    {
        Mail::fake();

        $note = new RequestCustomerUpdateApprovalAction(
            $this->draft(),
            $this->actingUser(),
            [self::RECIPIENT],
        )->execute();

        $this->assertNotNull($note);
        $this->assertTrue($note->isLocked());
        $this->assertFalse($note->isPublic(), 'an unapproved customer draft must not sit in a public feed');
        $this->assertTrue(MessageApproval::isPending($note));
        $this->assertSame(MessageApproval::KIND_EMAIL_DRAFT, MessageApproval::kind($note));
        $this->assertSame(CustomerUpdateApprovalHandler::class, MessageApproval::handler($note));
        $this->assertInstanceOf(ApprovalRequest::class, $note->pendingApproval());

        // The recipient rides on the message the way every other email draft carries it.
        $this->assertSame(self::RECIPIENT, $note->message['chat_jid']);

        Mail::assertNothingSent();
    }

    public function testApprovingSendsTheEmailAndOnlyThenAdvancesTheWatermark(): void
    {
        Mail::fake();

        $organization = $this->organization();
        $note = new RequestCustomerUpdateApprovalAction(
            $this->draft($organization),
            $this->actingUser(),
            [self::RECIPIENT],
        )->execute();

        $this->assertNull(
            $organization->get(DraftCustomerUpdateAction::WATERMARK_FIELD),
            'nothing is covered until the update has actually gone out'
        );

        $approved = new ApproveAgentMessageAction($note)->execute();

        Mail::assertSent(KanvasMailable::class, fn (KanvasMailable $mail): bool => $mail->hasTo(self::RECIPIENT));
        $this->assertFalse($approved->isLocked());
        $this->assertSame(MessageApproval::STATUS_APPROVED, MessageApproval::status($approved));
        $this->assertNotNull($organization->get(DraftCustomerUpdateAction::WATERMARK_FIELD));
    }

    /**
     * The card content is what a human reads and may rewrite, so the edited copy is what has to be
     * mailed — sending the original would ship what nobody signed off on.
     */
    public function testTheApproverSEditIsWhatGetsMailed(): void
    {
        Mail::fake();

        $note = new RequestCustomerUpdateApprovalAction(
            $this->draft(),
            $this->actingUser(),
            [self::RECIPIENT],
        )->execute();

        new ApproveAgentMessageAction($note, '# Rewritten by a human')->execute();

        Mail::assertSent(
            KanvasMailable::class,
            fn (KanvasMailable $mail): bool => str_contains($mail->render(), 'Rewritten by a human')
        );
    }

    private function draft(?Organization $organization = null): CustomerUpdateDraft
    {
        return new CustomerUpdateDraft(
            organization: $organization ?? $this->organization(),
            subject: 'Kanvas update — August',
            body: "August '26 Highlights\n\nIntro line.\n\nA headline\nA body sentence.\n\nThat is all.\nSee you next time.",
            coveredFrom: Carbon::now()->subDays(30),
            coveredThrough: Carbon::now(),
            releaseTags: ['v1.99.8'],
        );
    }

    private function organization(): Organization
    {
        $user = $this->actingUser();
        /** @var Companies $company */
        $company = $user->getCurrentCompany();

        return Organization::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'name' => 'Approval Flow Corp ' . fake()->unique()->uuid(),
            'address' => '',
            'total_employees' => 0,
        ]);
    }

    private function actingUser(): Users
    {
        /** @var Users $user */
        $user = auth()->user();

        return $user;
    }
}
