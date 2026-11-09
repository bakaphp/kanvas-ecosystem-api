<?php

declare(strict_types=1);

namespace Tests\Connectors\Traits;

use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Alanube\Enums\AccountModeEnum;
use Kanvas\Connectors\Alanube\Enums\ConfigurationEnum;
use Kanvas\Connectors\Alanube\Enums\WebhookEventEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\ConfigurationEnum as EcfConfigurationEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfProviderEnum;
use Kanvas\Workflow\Models\WorkflowAction;

/**
 * Alanube settings go on a throwaway company, never the shared test company: company settings are written to
 * Redis, which no transaction rolls back. The kanvas-mode main token is set through config, not the app
 * setting, for the same reason at app scope.
 *
 * Every token, RNC and id here is synthetic.
 */
trait HasAlanubeConfiguration
{
    protected const string ALANUBE_SANDBOX = 'https://sandbox.alanube.co/dom/v1/';
    protected const string ALANUBE_OWN_TOKEN = 'own-token-not-real';
    protected const string ALANUBE_MAIN_TOKEN = 'main-token-not-real';
    protected const string ALANUBE_COMPANY_ID = '01HZZZTESTCOMPANY0000000000';
    protected const string SENDER_RNC = '130000001';

    protected function alanubeCompany(AccountModeEnum $mode = AccountModeEnum::OWN): Companies
    {
        /** @var Companies $company */
        $company = Companies::factory()->create([
            'users_id' => Auth::id(),
            'is_active' => 1,
        ]);
        $company->associateApp(app(Apps::class));

        $company->set(ConfigurationEnum::ACCOUNT_MODE->value, $mode->value);
        $company->set(ConfigurationEnum::COMPANY_ID->value, self::ALANUBE_COMPANY_ID);
        $company->set(ConfigurationEnum::SENDER_PROFILE->value, $this->senderProfile());
        $company->set(EcfConfigurationEnum::ECF_PROVIDER->value, EcfProviderEnum::ALANUBE->value);

        if ($mode === AccountModeEnum::OWN) {
            $company->setEncrypted(ConfigurationEnum::API_TOKEN->value, self::ALANUBE_OWN_TOKEN);
        }

        config(['services.alanube.main_token_test' => self::ALANUBE_MAIN_TOKEN]);

        return $company;
    }

    /**
     * @return array<string, string>
     */
    protected function senderProfile(): array
    {
        return [
            'rnc' => self::SENDER_RNC,
            'companyName' => 'Empresa de Prueba SRL',
            'address' => 'Av. Winston Churchill 1',
            'municipality' => '010100',
            'province' => '010000',
            'mail' => 'facturas@example.com',
        ];
    }

    protected function registerAlanubeWorkflowActions(): void
    {
        foreach (WebhookEventEnum::cases() as $event) {
            WorkflowAction::firstOrCreate(
                ['model_name' => $event->jobClass()],
                ['name' => class_basename($event->jobClass())],
            );
        }
    }

    /**
     * @return array<string, string>
     */
    protected function secretHeader(string $secret): array
    {
        return [ConfigurationEnum::WEBHOOK_SECRET_HEADER => $secret];
    }
}
