<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CreateDealTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\TakeDealMessageTool;
use Kanvas\Intelligence\Notifications\ReceptionistMessageNotification;
use Tests\TestCase;

class TakeDealMessageToolTest extends TestCase
{
    public function testTakesMessageAndNotifiesOwner(): void
    {
        Notification::fake();

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $created = new CreateDealTool($app, $company, $user)
            ->__invoke(title: 'Deal ' . uniqid());

        $result = $this->withTenant(new TakeDealMessageTool())->__invoke(
            deal_id: (int) $created['deal_id'],
            message: 'Please call me back about the quote',
            for_whom: 'John',
            callback_number: '555-1234',
        );

        $this->assertSame('success', $result['status']);
        $this->assertTrue($result['recorded']);
        $this->assertTrue($result['owner_notified']);

        // owner defaults to the creating user on CreateDealTool
        Notification::assertSentTo($user, ReceptionistMessageNotification::class);
    }

    public function testEmptyMessageReturnsError(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $created = new CreateDealTool($app, $company, $user)
            ->__invoke(title: 'Deal ' . uniqid());

        $result = $this->withTenant(new TakeDealMessageTool())->__invoke(deal_id: (int) $created['deal_id'], message: '   ');

        $this->assertSame('error', $result['status']);
    }

    public function testHallucinatedDealIdReturnsError(): void
    {
        $result = $this->withTenant(new TakeDealMessageTool())->__invoke(deal_id: 999999999, message: 'hi');

        $this->assertSame('error', $result['status']);
    }

    /**
     * Deal tools resolve their deal against the tenant on their context, so a bare instance
     * (no withContext) intentionally resolves nothing — mirror what the agent wiring does.
     *
     * @template T of object
     *
     * @param T $tool
     *
     * @return T
     */
    private function withTenant(object $tool): object
    {
        $user = auth()->user();

        return $tool->withContext(app(Apps::class), $user->getCurrentCompany(), $user);
    }
}
