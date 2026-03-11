<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Facebook\Actions;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesSettings;
use Kanvas\Connectors\Facebook\Client as FacebookClient;
use Kanvas\Connectors\Facebook\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People;
use Kanvas\Guild\Leads\Actions\CreateLeadAction;
use Kanvas\Guild\Leads\Actions\CreateLeadTypeAction;
use Kanvas\Guild\Leads\DataTransferObject\Lead as LeadData;
use Kanvas\Guild\Leads\DataTransferObject\LeadType as LeadTypeData;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Guild\LeadSources\Actions\CreateLeadSourceAction;
use Kanvas\Guild\LeadSources\DataTransferObject\LeadSource as LeadSourceData;
use Kanvas\Workflow\Models\ReceiverWebhookCall;
use Spatie\LaravelData\DataCollection;

class CreateLeadFromFacebookAction
{
    public function __construct(
        protected ReceiverWebhookCall $webhookRequest
    ) {
    }

    public function execute(): array
    {
        /** @var array{entry?: list<array{id?: string, changes?: list<array{field?: string, value?: array{leadgen_id?: string, form_id?: string, ad_id?: string}}>}>} $payload */
        $payload = (array) $this->webhookRequest->payload;
        $receiver = $this->webhookRequest->receiverWebhook;
        $app = $receiver->app;
        $user = $receiver->user;

        /** @var array<string, mixed> $config */
        $config = $receiver->configuration ?? [];
        $graphVersion = (string) ($app->get(ConfigurationEnum::GRAPH_API_VERSION->value) ?? 'v21.0');

        $createdLeads = [];

        $entries = $payload['entry'] ?? [];

        foreach ($entries as $entry) {
            $pageId = $entry['id'] ?? '';
            $changes = $entry['changes'] ?? [];

            foreach ($changes as $change) {
                if (($change['field'] ?? '') !== 'leadgen') {
                    continue;
                }

                $value = $change['value'] ?? [];
                $leadgenId = $value['leadgen_id'] ?? '';
                $formId = $value['form_id'] ?? '';
                $adId = $value['ad_id'] ?? '';

                if ($leadgenId === '') {
                    continue;
                }

                $pageConfig = $this->getPageConfig($app, $pageId);
                $pageAccessToken = $pageConfig['token'];
                $company = $pageConfig['company'];

                if ($pageAccessToken === '' || $company === null) {
                    $createdLeads[] = [
                        'leadgen_id' => $leadgenId,
                        'error' => 'No page access token or company found for page ' . $pageId,
                    ];

                    continue;
                }

                $branch = $company->defaultBranch;

                $leadData = FacebookClient::getLeadData(
                    $leadgenId,
                    $pageAccessToken,
                    $graphVersion
                );
                $fields = $this->extractFieldData($leadData['field_data'] ?? []);

                if (empty($fields)) {
                    /** @var string $rawResponse */
                    $rawResponse = json_encode($leadData);

                    throw new ValidationException(
                        'Facebook lead ' . $leadgenId . ' returned empty field_data. Raw response: ' . $rawResponse
                    );
                }

                $email = $fields['email'] ?? '';
                $phone = $fields['phone_number'] ?? $fields['phone'] ?? '';

                ['firstname' => $firstname, 'lastname' => $lastname] = Str::parseFullName(
                    fullName: $fields['full_name'] ?? '',
                    firstname: $fields['first_name'] ?? '',
                    lastname: $fields['last_name'] ?? '',
                );

                $title = trim($firstname . ' ' . $lastname);

                if ($title === '') {
                    $title = $email !== '' ? $email : 'Facebook Lead ' . $leadgenId;
                }

                $contacts = [];
                if ($email !== '') {
                    $contacts[] = ['type' => 'email', 'value' => $email];
                }
                if ($phone !== '') {
                    $contacts[] = ['type' => 'phone', 'value' => $phone];
                }

                $pipelineStageId = (int) ($config['pipeline_stage_id'] ?? 0);
                $sourceId = $this->resolveSourceId($app, $company, $config);
                $typeId = $this->resolveTypeId($app, $company, $config);

                $leadDto = new LeadData(
                    app: $app,
                    branch: $branch,
                    user: $user,
                    title: $title,
                    pipeline_stage_id: $pipelineStageId,
                    people: People::from([
                        'app' => $app,
                        'branch' => $branch,
                        'user' => $user,
                        'firstname' => $firstname,
                        'lastname' => $lastname,
                        'contacts' => Contact::collect($contacts, DataCollection::class),
                        'address' => Address::collect([], DataCollection::class),
                        'id' => 0,
                    ]),
                    type_id: $typeId,
                    source_id: $sourceId,
                    custom_fields: [
                        'facebook_leadgen_id' => $leadgenId,
                        'facebook_form_id' => $formId,
                        'facebook_ad_id' => $adId,
                        'facebook_page_id' => $pageId,
                        'facebook_lead_data' => $fields,
                    ],
                );

                $lead = new CreateLeadAction($leadDto)->execute();

                $createdLeads[] = [
                    'lead_id' => $lead->getId(),
                    'leadgen_id' => $leadgenId,
                ];
            }
        }

        return [
            'message' => 'Processed ' . count($createdLeads) . ' Facebook leads',
            'leads' => $createdLeads,
        ];
    }

    /**
     * Extract field_data array into key-value pairs.
     *
     * @param list<array{name: string, values: list<string>}> $fieldData
     * @return array<string, string>
     */
    protected function extractFieldData(array $fieldData): array
    {
        $fields = [];

        foreach ($fieldData as $field) {
            $name = $field['name'] ?? '';
            $values = $field['values'] ?? [];
            $fields[$name] = $values[0] ?? '';
        }

        return $fields;
    }

    protected function resolveSourceId(AppInterface $app, Companies $company, array $config): int
    {
        if (! empty($config['source_id'])) {
            return (int) $config['source_id'];
        }

        $typeId = $this->resolveTypeId($app, $company, $config);

        /** @var Apps $app */
        $source = new CreateLeadSourceAction(
            new LeadSourceData(
                app: $app,
                company: $company,
                leads_types_id: $typeId,
                name: 'Meta',
                is_active: true,
                description: 'Leads coming from Meta (Facebook/Instagram)',
            ),
        )->execute();

        return (int) $source->getId();
    }

    protected function resolveTypeId(AppInterface $app, Companies $company, array $config): int
    {
        if (! empty($config['type_id'])) {
            return (int) $config['type_id'];
        }

        $type = LeadType::where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('name', 'Internet')
            ->where('is_deleted', 0)
            ->first();

        if ($type) {
            return (int) $type->getId();
        }

        /** @var Apps $app */
        $type = new CreateLeadTypeAction(
            new LeadTypeData(
                apps: $app,
                companies: $company,
                name: 'Internet',
                description: 'Internet leads',
                is_active: 1,
            ),
        )->execute();

        return (int) $type->getId();
    }

    protected function getPageConfig(AppInterface $app, string $pageId): array
    {
        $baseKey = ConfigurationEnum::PAGE_ACCESS_TOKEN->value . '-' . (int) $app->getId() . '-';

        $setting = CompaniesSettings::where('name', 'LIKE', $baseKey . '%-' . $pageId)
            ->first();

        if (! $setting) {
            return ['token' => '', 'company' => null];
        }

        /** @var array<string, mixed>|string|null $pageConfig */
        $pageConfig = $setting->value;
        $token = '';

        if (is_array($pageConfig) && isset($pageConfig['access_token'])) {
            $token = (string) $pageConfig['access_token'];
        } elseif (is_string($pageConfig) && $pageConfig !== '') {
            $token = $pageConfig;
        }

        return [
            'token' => $token,
            'company' => $setting->company,
        ];
    }
}
