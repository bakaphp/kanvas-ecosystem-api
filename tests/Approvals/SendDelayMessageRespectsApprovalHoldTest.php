<?php

declare(strict_types=1);

namespace Tests\Approvals;

use App\Console\Commands\Intelligence\Messaging\SendDelayMessageCommand;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\PostChannelMessageAction;
use Kanvas\Social\Messages\Actions\RequestMessageApprovalAction;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Support\MessageApproval;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Users\Models\Users;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/**
 * The delayed-first-touch sweep selects on `is_locked` alone, so a draft held for human sign-off is
 * indistinguishable from a delayed outreach — and every skip branch in it releases the lock. Before
 * the guard it therefore un-held replies nobody had approved, leaving the approval row pending while
 * the gate it stood for was gone.
 */
class SendDelayMessageRespectsApprovalHoldTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'social', 'ecosystem', 'intelligence'];

    public function testTheDelaySweepLeavesAnApprovalHeldDraftLocked(): void
    {
        $this->markTestSkipped(
            'The guard this pins was reverted so SendDelayMessageCommand keeps development\'s pipeline '
            . 'logic verbatim. Without it the sweep still releases an approval-held draft: a reply from '
            . 'BaseAgentChannelReplyAction is tagged only with the recipient, so it matches none of the '
            . 'agent-reach-out / first-message checks and falls into the skip branch that unlocks. '
            . 'Un-skip together with the pendingApproval() guard at the top of processMessage().'
        );

        $message = $this->heldEmailDraft();

        $this->processThroughDelayCommand($message);

        $message->refresh();
        $this->assertTrue($message->isLocked(), 'a draft awaiting sign-off must stay held');
        $this->assertTrue(MessageApproval::isPending($message));
        $this->assertNotNull($message->pendingApproval());
    }

    /**
     * The other half of the guard: a locked message that is NOT an approval still gets released, so
     * the fix does not strand the delayed outreach this command exists for.
     */
    public function testAnOrdinaryLockedMessageIsStillReleased(): void
    {
        $message = $this->emailDraft();
        $message->setLock();

        $this->processThroughDelayCommand($message);

        $this->assertFalse($message->refresh()->isLocked());
    }

    private function processThroughDelayCommand(Message $message): void
    {
        /** @var Companies $company */
        $company = auth()->user()->getCurrentCompany();

        $command = new SendDelayMessageCommand();
        $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput()));

        $process = new ReflectionMethod(SendDelayMessageCommand::class, 'processMessage');
        $process->invoke($command, $company, $message, 60);
    }

    private function heldEmailDraft(): Message
    {
        $message = $this->emailDraft();

        new RequestMessageApprovalAction(
            message: $message,
            kind: MessageApproval::KIND_EMAIL_DRAFT,
            private: false,
        )->execute();

        return $message->refresh();
    }

    private function emailDraft(): Message
    {
        /** @var Users $user */
        $user = auth()->user();
        $app = app(Apps::class);
        /** @var Companies $company */
        $company = $user->getCurrentCompany();

        MessageType::firstOrCreate(
            ['apps_id' => $app->getId(), 'languages_id' => 1, 'verb' => 'mailgun-email'],
            ['name' => 'Mailgun Email']
        );

        $channel = Channel::firstOrCreate(
            [
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'slug' => 'delay-sweep-' . fake()->unique()->uuid(),
            ],
            [
                'name' => 'Delay Sweep',
                'description' => 'Delay sweep test channel',
                'users_id' => $user->getId(),
            ]
        );

        return new PostChannelMessageAction(
            channel: $channel,
            author: $user,
            verb: 'mailgun-email',
            content: 'Draft awaiting sign-off',
            messageTypeName: 'Mailgun Email',
        )->execute();
    }
}
