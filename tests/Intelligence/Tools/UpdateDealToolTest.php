<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CreateDealTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\UpdateDealTool;
use Tests\TestCase;

class UpdateDealToolTest extends TestCase
{
    public function testUpdatesTitleAndStatus(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $created = new CreateDealTool($app, $company, $user)
            ->__invoke(title: 'Before ' . uniqid());
        $dealId = (int) $created['deal_id'];

        $newTitle = 'After ' . uniqid();
        $result = new UpdateDealTool($app, $company, $user)
            ->__invoke(deal_id: $dealId, title: $newTitle, status: 2);

        $this->assertSame('success', $result['status']);

        $deal = Deal::getById($dealId);
        $this->assertSame($newTitle, $deal->title);
        $this->assertSame(2, $deal->status);
    }

    public function testNoopWhenNothingProvided(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $created = new CreateDealTool($app, $company, $user)
            ->__invoke(title: 'Deal ' . uniqid());

        $result = new UpdateDealTool($app, $company, $user)
            ->__invoke(deal_id: (int) $created['deal_id']);

        $this->assertSame('noop', $result['status']);
    }

    public function testHallucinatedDealIdReturnsError(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $result = new UpdateDealTool($app, $company, $user)
            ->__invoke(deal_id: 999999999, title: 'Nope');

        $this->assertSame('error', $result['status']);
    }
}
