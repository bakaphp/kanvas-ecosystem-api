<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Facebook\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Companies\Models\CompaniesSettings;
use Kanvas\Connectors\Facebook\Client as FacebookClient;
use Kanvas\Connectors\Facebook\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People;
use Kanvas\Guild\Leads\Actions\CreateLeadAction;
use Kanvas\Guild\Leads\DataTransferObject\Lead as LeadData;
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
        $company = $receiver->company;
        $user = $receiver->user;
        $branch = $company->defaultBranch;

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

                $pageAccessToken = $this->getPageAccessToken($company, $app, $pageId);

                if ($pageAccessToken === '') {
                    $createdLeads[] = [
                        'leadgen_id' => $leadgenId,
                        'error' => 'No page access token found for page ' . $pageId,
                    ];

                    continue;
                }

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

                $firstname = $fields['first_name'] ?? '';
                $lastname = $fields['last_name'] ?? '';
                $email = $fields['email'] ?? '';
                $phone = $fields['phone_number'] ?? $fields['phone'] ?? '';
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
                $sourceId = (int) ($config['source_id'] ?? 0);

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

    protected function getPageAccessToken(
        CompanyInterface $company,
        AppInterface $app,
        string $pageId
    ): string {
        // Key format: facebook_page_access_token-{appId}-{companyId}-{pageId}
        // Try the receiver's company first, then fall back to any company in case
        // the OAuth was completed by a different company
        $baseKey = ConfigurationEnum::PAGE_ACCESS_TOKEN->value . '-' . (int) $app->getId() . '-';
        $exactKey = $baseKey . (int) $company->getId() . '-' . $pageId;

        $setting = CompaniesSettings::where('name', $exactKey)
            ->first();

        if (! $setting) {
            $setting = CompaniesSettings::where('name', 'LIKE', $baseKey . '%-' . $pageId)
                ->first();
        }

        if (! $setting) {
            return '';
        }

        /** @var array<string, mixed>|string|null $pageConfig */
        $pageConfig = $setting->value;

        if (is_array($pageConfig) && isset($pageConfig['access_token'])) {
            return (string) $pageConfig['access_token'];
        }

        if (is_string($pageConfig) && $pageConfig !== '') {
            return $pageConfig;
        }

        return '';
    }
}
