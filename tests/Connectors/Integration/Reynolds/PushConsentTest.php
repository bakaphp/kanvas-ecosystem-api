<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Reynolds;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Reynolds\Actions\SyncPeopleAction;
use Kanvas\Connectors\Reynolds\DataTransferObject\Customer;
use Kanvas\Connectors\Reynolds\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;
use Tests\TestCase;

final class PushConsentTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'ecosystem'];

    public function testTheStoredFlagGoesBackAsItIs(): void
    {
        $people = $this->people();
        $people->set(CustomFieldEnum::CONSENT_OPT_OUT->value, 'Y');

        $this->assertSame('Y', Customer::fromPeople($people)->toConsent()['OptOut']);
    }

    public function testNothingIsPushedForAFlagKanvasNeverReceived(): void
    {
        $consent = Customer::fromPeople($this->people())->toConsent();

        $this->assertArrayNotHasKey('OptOut', $consent, 'an invented N would overwrite what the CRM holds');
    }

    public function testAnEmptyConsentIsNotSentAtAll(): void
    {
        $payload = new SyncPeopleAction($this->people())->execute();

        $this->assertArrayNotHasKey('Consent', $payload);
    }

    private function people(): People
    {
        return People::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => auth()->user()->getCurrentCompany()->getId(),
            'users_id' => auth()->user()->getId(),
            'firstname' => 'Push',
            'lastname' => 'Consent',
            'name' => 'Push Consent',
        ]);
    }
}
