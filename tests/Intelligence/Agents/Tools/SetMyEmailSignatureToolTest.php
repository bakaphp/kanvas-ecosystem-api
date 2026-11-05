<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\System\SetMyEmailSignatureTool;
use Kanvas\Notifications\Support\UserEmailSignature;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class SetMyEmailSignatureToolTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'intelligence'];

    private Users $user;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = auth()->user();
        $this->company = Companies::factory()->create();
    }

    protected function tearDown(): void
    {
        // user_config lives in Redis + ecosystem and is shared by every test run on this user.
        $this->user->del(UserEmailSignature::companySettingKey($this->company));

        parent::tearDown();
    }

    public function testSavesTheSignatureForTheCurrentCompanyOnly(): void
    {
        $globalBefore = $this->user->get(UserEmailSignature::SETTING);

        $result = $this->tool()->__invoke(text: "  Jane Doe\nSales Manager  ");

        $this->assertTrue($result['saved']);
        $this->assertSame('this_company', $result['scope']);
        $this->assertSame("Jane Doe\nSales Manager", UserEmailSignature::fromUser($this->user, $this->company)?->text);
        $this->assertSame($globalBefore, $this->user->get(UserEmailSignature::SETTING));
    }

    public function testTurningItOffKeepsTheTextAndStopsSigning(): void
    {
        $this->tool()->__invoke(text: 'Jane Doe');

        $result = $this->tool()->__invoke(enabled: false);

        $this->assertTrue($result['saved']);
        $this->assertSame('Jane Doe', $result['text']);
        $saved = UserEmailSignature::decodeSetting($this->user->get(UserEmailSignature::companySettingKey($this->company)));
        $this->assertFalse((bool) $saved['append']);
    }

    public function testNoTextAndNothingSavedIsAFixableArgumentNotASave(): void
    {
        $result = $this->tool()->__invoke(text: '   ');

        $this->assertFalse($result['saved']);
        $this->assertFalse($result['success']);
        $this->assertNull($this->user->get(UserEmailSignature::companySettingKey($this->company)));
    }

    public function testRefusesWhenTheTurnIsActedByTheAgentItself(): void
    {
        $agent = Agent::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($this->company->getId())
            ->create(['user_id' => $this->user->getId()]);

        $result = new SetMyEmailSignatureTool()
            ->withContext(
                app(Apps::class),
                $this->company,
                $this->user,
                $agent,
            )
            ->__invoke(text: 'Agent Signature');

        $this->assertFalse($result['saved']);
        $this->assertSame('agent_caller', $result['status']);
        $this->assertNull($this->user->get(UserEmailSignature::companySettingKey($this->company)));
    }

    public function testADraftIdThatWasNeverPreviewedForThisPersonIsNotSaved(): void
    {
        $result = $this->tool()->__invoke(draft_id: 'deadbeef00');

        $this->assertFalse($result['saved']);
        $this->assertSame('not_found', $result['outcome']);
        $this->assertNull($this->user->get(UserEmailSignature::companySettingKey($this->company)));
    }

    public function testUsingItEverywhereCopiesTheDesignInEffectForThisCompany(): void
    {
        $globalBefore = $this->user->get(UserEmailSignature::SETTING);
        $this->user->del(UserEmailSignature::SETTING);
        $this->user->set(
            UserEmailSignature::companySettingKey($this->company),
            ['append' => true, 'text' => 'Jane Doe', 'html' => '<b>Jane Doe</b>']
        );

        try {
            $result = $this->tool()->__invoke(all_companies: true);

            $global = UserEmailSignature::decodeSetting($this->user->get(UserEmailSignature::SETTING));
            $this->assertTrue($result['saved']);
            $this->assertSame('<b>Jane Doe</b>', $global['html']);
        } finally {
            $this->user->del(UserEmailSignature::SETTING);
            if ($globalBefore !== null) {
                $this->user->set(UserEmailSignature::SETTING, $globalBefore);
            }
        }
    }

    public function testTurningOffAnApprovedDesignKeepsTheDesign(): void
    {
        $key = UserEmailSignature::companySettingKey($this->company);
        $this->user->set($key, ['append' => true, 'text' => 'Jane Doe', 'html' => '<b>Jane Doe</b>']);

        $result = $this->tool()->__invoke(enabled: false);

        $saved = UserEmailSignature::decodeSetting($this->user->get($key));
        $this->assertTrue($result['saved']);
        $this->assertSame('html', $result['design']);
        $this->assertSame('<b>Jane Doe</b>', $saved['html']);
        $this->assertFalse((bool) $saved['append']);
    }

    private function tool(): SetMyEmailSignatureTool
    {
        return new SetMyEmailSignatureTool()->withContext(app(Apps::class), $this->company, $this->user);
    }
}
