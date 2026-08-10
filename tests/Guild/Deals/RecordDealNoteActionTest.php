<?php

declare(strict_types=1);

namespace Tests\Guild\Deals;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Deals\Actions\RecordDealNoteAction;
use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CreateDealTool;
use Tests\TestCase;

class RecordDealNoteActionTest extends TestCase
{
    private function makeDeal(): Deal
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $created = new CreateDealTool($app, $company, $user)
            ->__invoke(title: 'Deal ' . uniqid());

        return Deal::getById((int) $created['deal_id']);
    }

    public function testNoteIsAttributedToTheActingUser(): void
    {
        $user = auth()->user();
        $deal = $this->makeDeal();

        $note = new RecordDealNoteAction($deal)->execute('Meeting recap', 'note', $user);

        $this->assertNotNull($note);
        // The note must be stamped as the acting agent's user, NOT the shared company AI user.
        $this->assertSame($user->getId(), (int) $note->users_id);
    }
}
