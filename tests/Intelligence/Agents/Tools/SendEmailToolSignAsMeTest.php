<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Redis;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SendEmailTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SendPersonEmailTool;
use Kanvas\Notifications\Support\UserEmailSignature;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Users\Models\Users;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\MakesAgents;

/**
 * An internal assistant writes either as the person it is talking with or as the business / its
 * customer-facing persona. Only `sign_as_me` puts that person's own signature under the email.
 */
final class SendEmailToolSignAsMeTest extends TestCase
{
    use DatabaseTransactions;
    use MakesAgents;

    private ?Users $signed = null;

    protected function tearDown(): void
    {
        // Settings are written to Redis as well, which the transaction does not roll back.
        if ($this->signed !== null) {
            Redis::del($this->signed->getSettingsRedisPrimaryKey());
        }

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: bool|null, 1: bool}>
     */
    public static function signAsMeCases(): array
    {
        return [
            'written as the person talking' => [true, true],
            'written as the business persona' => [false, false],
            'not stated' => [null, false],
        ];
    }

    #[DataProvider('signAsMeCases')]
    public function testLeadEmailCarriesTheHumansSignatureOnlyWhenWrittenAsThem(?bool $signAsMe, bool $signed): void
    {
        $human = $this->humanWithSignature();
        $company = $human->getCurrentCompany();
        $app = app(Apps::class);

        $lead = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();
        $lead->people->addEmail('prospect-' . $lead->getId() . '@example.com');

        $tool = new SendEmailTool()
            ->withContext($app, $company, $human, $this->makeSigningAgent($company))
            ->forConversationHuman($human);

        $arguments = ['lead_id' => $lead->getId(), 'subject' => 'Following up', 'body' => 'Still interested?'];
        if ($signAsMe !== null) {
            $arguments['sign_as_me'] = $signAsMe;
        }
        $result = $tool->__invoke(...$arguments);

        $this->assertSame('success', $result['status'], json_encode($result));
        $data = $this->assertSignature($signed);
        // Written as the business agent, the template's own agent signature closes it instead.
        $this->assertSame(! $signed, $data['signature']);
    }

    #[DataProvider('signAsMeCases')]
    public function testPersonEmailCarriesTheHumansSignatureOnlyWhenWrittenAsThem(?bool $signAsMe, bool $signed): void
    {
        $human = $this->humanWithSignature();
        $company = $human->getCurrentCompany();
        $app = app(Apps::class);

        $person = People::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();
        $person->addEmail('contact-' . $person->getId() . '@example.com');

        $tool = new SendPersonEmailTool()
            ->withContext($app, $company, $human, $this->makeSigningAgent($company))
            ->forConversationHuman($human);

        $arguments = ['person_id' => $person->getId(), 'subject' => 'Following up', 'body' => 'Still interested?'];
        if ($signAsMe !== null) {
            $arguments['sign_as_me'] = $signAsMe;
        }
        $result = $tool->__invoke(...$arguments);

        $this->assertTrue($result['success'] ?? false, json_encode($result));
        $this->assertSignature($signed);
    }

    private function humanWithSignature(): Users
    {
        // CI's SMTP host may be Mailgun's, which files a delivery; that lane has its own suite.
        config(['mail.mailers.smtp.host' => 'smtp.example.com']);
        Notification::fake();

        $human = auth()->user();
        $this->signed = $human;
        $signature = ['append' => true, 'text' => "Jane Rep\nInternet Sales"];
        // A company signature that is off falls back to the global one, so both are pinned.
        $human->set(UserEmailSignature::companySettingKey($human->getCurrentCompany()), $signature);
        $human->set(UserEmailSignature::SETTING, $signature);

        return $human;
    }

    /**
     * @return array<string, mixed>
     */
    private function assertSignature(bool $signed): array
    {
        $sent = Notification::sent(new AnonymousNotifiable(), Blank::class);
        $this->assertCount(1, $sent);
        $data = $sent->first()->getData();

        if (! $signed) {
            $this->assertArrayNotHasKey('user_signature', $data);

            return $data;
        }

        $this->assertStringContainsString(UserEmailSignature::MARKER, $data['user_signature']);
        $this->assertStringContainsString('Jane Rep', $data['user_signature']);

        return $data;
    }
}
