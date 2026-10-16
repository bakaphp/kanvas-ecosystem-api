<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\VinSolution;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\VinSolution\Actions\PushNoteToLeadAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Tests\TestCase;

final class PushNoteToLeadActionTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'ecosystem', 'crm', 'social'];

    public function testMessageWithoutEngagementSkipsInsteadOfPushingNullNote(): void
    {
        $this->assertSame([], $this->makeAction()->execute());
    }

    public function testBlankExplicitNoteIsSkipped(): void
    {
        $this->assertSame([], $this->makeAction()->execute('   '));
    }

    private function makeAction(): PushNoteToLeadAction
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        $message = Message::factory()->create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'message' => ['verb' => 'trade-walk', 'status' => 'submitted'],
        ]);

        return new PushNoteToLeadAction(lead: $lead, message: $message);
    }
}
