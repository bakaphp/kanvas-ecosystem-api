<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Reynolds;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Reynolds\Activities\PushLeadNotesActivity;
use Kanvas\Connectors\Reynolds\Enums\ConfigurationEnum;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\TestCase;

final class PushLeadNotesActivityTest extends TestCase
{
    /**
     * A Reynolds rule fires on every message of its type, including ones that were never linked to
     * a Lead — so a missing entity is a routine skip, not a fault. It used to throw and reached
     * Sentry 13k times in two weeks (KANVAS-ECOSYSTEM-69R).
     */
    public function testFailsTheWorkflowWhenTheMessageHasNoLeadInsteadOfThrowing(): void
    {
        $this->configureReynolds();

        $result = $this->activity()->execute(
            $this->makeMessageWithoutEntity(),
            app(Apps::class),
            []
        );

        $this->assertSame('Lead not found', $result['error']);
    }

    private function activity(): PushLeadNotesActivity
    {
        return new PushLeadNotesActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );
    }

    private function configureReynolds(): void
    {
        $company = $this->company();

        $company->set(ConfigurationEnum::REYNOLDS_ENDPOINT->value, 'https://example.com/salesassist');
        $company->set(ConfigurationEnum::REYNOLDS_USERNAME->value, 'user');
        $company->set(ConfigurationEnum::REYNOLDS_PASSWORD->value, 'secret');
        $company->set(ConfigurationEnum::REYNOLDS_DEALER_NUMBER->value, '1');
        $company->set(ConfigurationEnum::REYNOLDS_STORE_NUMBER->value, '2');
        $company->set(ConfigurationEnum::REYNOLDS_AREA_NUMBER->value, '3');
    }

    private function makeMessageWithoutEntity(): Message
    {
        return Message::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($this->company()->getId())
            ->create(['message' => ['content' => 'Plain note, never linked to a lead']]);
    }

    private function company(): Companies
    {
        return auth()->user()->getCurrentCompany();
    }
}
