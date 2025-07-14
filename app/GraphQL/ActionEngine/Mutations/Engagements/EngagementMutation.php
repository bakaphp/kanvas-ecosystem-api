<?php

declare(strict_types=1);

namespace App\GraphQL\ActionEngine\Mutations\Engagements;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Baka\Support\Url;
use Kanvas\ActionEngine\Actions\Enums\ActionEnum;
use Kanvas\ActionEngine\Actions\Models\Action;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Actions\Models\CompanyActionVisitor;
use Kanvas\ActionEngine\Engagements\DataTransferObject\EngagementMessage;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\ActionEngine\Pipelines\Models\Pipeline;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;

class EngagementMutation
{
    /**
     * @todo add test
     */
    public function startEngagement(mixed $rootValue, array $request): Engagement
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $request = $request['input'];

        $lead = Lead::getByIdFromCompanyApp($request['lead_id'], $company, $app);
        $user->follow($lead);

        $people = ! empty($request['people_id']) ? People::getByIdFromCompanyApp($request['people_id'], $company, $app) : $lead->people;
        $receiver = ! empty($request['receiver_id']) ? LeadReceiver::getByIdFromCompanyApp($request['receiver_id'], $company, $app) : ($lead->receiver ?? LeadReceiver::getDefault($company, $app));
        $requestId = $request['request_id'];
        $parentAction = $this->getActionInfo($app, $request['action']);
        $action = $parentAction['parent'];

        $checkListId = $request['task_id'] ?? 0;
        $source = $request['source'];
        $via = $request['via'] ?? 'copy';
        $data = $request['data'] ?? [];

        $newCreditApp = [
            ActionEnum::CREDIT_APP_2->value,
            ActionEnum::CREDIT_APP_3->value,
            ActionEnum::CREDIT_APP_4->value,
            ActionEnum::CREDIT_APP_5->value,
            ActionEnum::CREDIT_APP_6->value,
            ActionEnum::CREDIT_APP_7->value,
        ];

        $newCosigner = [
            ActionEnum::CO_SIGNER_2->value,
            ActionEnum::CO_SIGNER_3->value,
            ActionEnum::CO_SIGNER_4->value,
            ActionEnum::CO_SIGNER_5->value,
        ];

        $codeShare = [
            ActionEnum::SHARE_BLUELINK->value,
            ActionEnum::SHARE_ELECTRIFY_AMERICA->value,
        ];

        $companyAction = CompanyAction::getByAction(
            Action::getBySlug($action, $company),
            $company,
            $app,
            $lead->branch
        );
        $companyActionParent = $companyAction;

        /**
         * @todo clean this
         */
        $newActionPageUrl = in_array($action, $app->get('new-action-slug') ?? []);
        $actionPageUrl = ! $newActionPageUrl ? $app->get('TEMP_LANDING_PAGE') : $app->get('NEW_LANDING_PAGE');

        if (in_array($action, $newCreditApp)) {
            $request['mixed_credit_app'] = $action;
            $action = ActionEnum::CREDIT_APP->value;
            //$request['actions_slug'] = $action;
            $formType = [
                ActionEnum::CREDIT_APP_2->value => 'finance-lease',
                ActionEnum::CREDIT_APP_3->value => 'personal-check',
                ActionEnum::CREDIT_APP_4->value => 'cashier-check',
                ActionEnum::CREDIT_APP_5->value => 'all-cash',
                ActionEnum::CREDIT_APP_6->value => '5-liner',
                ActionEnum::CREDIT_APP_7->value => 'finance',
            ];
            $request['form_type'] = $formType[$request['mixed_credit_app']];
        }

        if (in_array($action, $newCosigner)) {
            $request['mixed_cosigner_app'] = $action;
            $action = ActionEnum::CO_SIGNER->value;
            //$request['actions_slug'] = $action;
            $formType = [
                ActionEnum::CO_SIGNER_2->value => 'finance-lease',
                ActionEnum::CO_SIGNER_3->value => 'personal-check',
                ActionEnum::CO_SIGNER_4->value => 'cashier-check',
                ActionEnum::CO_SIGNER_5->value => 'all-cash',
            ];
            $request['form_type'] = $formType[$request['mixed_cosigner_app']];
        }

        if (in_array($action, $codeShare)) {
            $request['mixed_share_code'] = $action;
            $action = ActionEnum::SHARE_BLUELINK->value;
            //$request['actions_slug'] = $action;
        }

        if (isset($request['mixed_credit_app']) || isset($request['mixed_cosigner_app'])) {
            $companyActionParent = CompanyAction::getByAction(
                Action::getBySlug($action, $company),
                $company,
                $app,
                $lead->branch
            );
        }

        $request['visitors_id'] = $requestId;
        $request['visitor_id'] = $request['visitors_id'];
        $request['users_id'] = $user->getId();
        $request['leads_id'] = $lead->uuid;
        $request['lead_id'] = $lead->uuid;
        $request['contact_id'] = $people->uuid;
        $request['vehicle_id'] = null;
        $request['receivers_id'] = $receiver->uuid;
        $request['receiver_id'] = $receiver->uuid;
        //$request['receiver_id'] = $request['receiver_id'];
        $request['contacts_id'] = $people->uuid;
        $request['request'] = json_encode($request);
        $request['actions_slug'] = $action;
        $request['cid'] = $lead->company->uuid;
        $request['bcid'] = $lead->branch ? $lead->branch->uuid : null;
        $request['company_action_id'] = $companyAction->getId();
        $request['extraField'] = $request['extraField'] ?? [];

        if (! empty($parentAction['form_type'])) {
            $request['form_type'] = $parentAction['form_type'];
        }

        $extraField = ! empty($request['extraField']) ? $request['extraField'] : null;
        $companyActionId = $request['company_action_id'] ?? null;

        if (is_array($extraField)) {
            $extraField = implode('&', $extraField);
        }

        $params = array_intersect_key(
            $request,
            array_flip([
                'visitors_id',
                'visitor_id',
                'users_id',
                'vehicle_id',
                'leads_id',
                'lead_id',
                'receivers_id',
                'receiver_id',
                'contacts_id',
                'actions_slug',
                'cid',
                'bcid',
                'form_type',
            ])
        );

        $companyActionVisitor = CompanyActionVisitor::create([
            'visitors_id' => $request['request_id'],
            'leads_id' => $lead->uuid,
            'receivers_id' => $receiver->uuid,
            'contacts_id' => $people->uuid,
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'companies_actions_id' => $companyActionParent->getId(),
            'actions_slug' => $request['action'],
            'request' => $request,
        ]);

        $urlParams = http_build_query($params) . $extraField;
        $urlParams .= '&caction=' . $companyAction->uuid;

        $companyLanguage = $lead->company->get('COMPANY_MULTI_LANGUAGE'); // Adjust flag name as needed
        if ($companyLanguage) {
            $urlParams .= '&lang=' . $companyLanguage;
        }

        $url = $actionPageUrl . "/{$action}?{$urlParams}";
        $urlPreview = $actionPageUrl . "/{$action}?{$urlParams}&preview=true";

        $reasonEnglish = $companyAction->get('reasonEn');
        $reasonSpanish = $companyAction->get('reasonEs');

        $messageEnglish = 'Hi {name}, this is ' . $user->firstname . ' from ' . $lead->branch->name . '. Click the link below to ' . $reasonEnglish;
        $messageSpanish = 'Hola {name}, es ' . $user->firstname . ' de ' . $lead->branch->name . '. Haz click al siguiente enlace para ' . $reasonSpanish;

        $messageData = [
            'link' => Url::getShortUrl($url, $app),
            'link_preview' => Url::getShortUrl($urlPreview, $app),
            'link_full' => $url,
            'link_full_preview' => $urlPreview,
            'data' => $companyAction->form_config,
            'params' => $request,
            'preview_image' => null,
            'message_content' => [
                'ENG' => $reasonEnglish !== null && Str::endsWith($reasonEnglish, '!') ? $messageEnglish : $messageEnglish . '. ',
                'ES' => $reasonSpanish !== null && Str::endsWith($reasonSpanish, '!') ? $messageSpanish : $messageSpanish . '. ',
            ],
        ];

        $channel = new CreateChannelAction(new Channel(
            apps: $app,
            companies: $lead->company,
            users: $lead->user,
            entity_id: $lead->getId(),
            entity_namespace: Lead::class,
            name: $lead->uuid,
            slug: $lead->uuid,
            description: $lead->uuid,
        ))->execute();

        $engagementMessage = new EngagementMessage(
            data: $data, //array_merge($data, $messageData),
            text: $companyAction->name,
            verb: $action,
            status: ActionStatusEnum::SENT->value,
            actionLink: $messageData['link'],
            source: $source,
            linkPreview: $messageData['link_preview'],
            engagementStatus: ActionStatusEnum::SENT->value,
            visitorId: $requestId,
            hashtagVisited: $companyAction->name,
            userUuid: $user->uuid,
            contactUuid: $people->uuid,
            checkListId: $checkListId,
            preFill: [],
            via: $via,
            product_id: $data['product_id'] ?? null,
            channel_id: $channel ? (string) $channel->uuid : null,
        );
        $messageInput = [
            'message' => $engagementMessage->toArray(),
            'reactions_count' => 0,
            'comments_count' => 0,
            'total_liked' => 0,
            'total_disliked' => 0,
            'total_saved' => 0,
            'total_shared' => 0,
            'ip_address' => request()->ip(),
        ];

        $messageTypeDto = MessageTypeInput::from([
            'apps_id' => $app->getId(),
            'name' => $action,
            'verb' => $action,
        ]);
        $messageType = (new CreateMessageTypeAction($messageTypeDto))->execute();

        $createMessage = (new CreateMessageAction(
            MessageInput::fromArray(
                $messageInput,
                $user,
                $messageType,
                $company,
                $app
            ),
            SystemModulesRepository::getByModelName(Lead::class, $app),
            $lead->getId()
        ))->execute();

        $pipeline = Pipeline::getBySlug($action, $app, $company);
        $stage = $pipeline->stages()->where('slug', ActionStatusEnum::SENT->value)->firstOrFail();

        if ($channel) {
            $channel->addMessage($createMessage, $user);
        }
        //save share history en company action history
        //generate link
        //create msg
        //create engagement
        //return engagement

        $engagement = Engagement::firstOrCreate([
            'companies_id' => $company->getId(),
            'apps_id' => $app->getId(),
            'users_id' => $user->getId(),
            'leads_id' => $lead->getId(),
            'people_id' => $people->getId(),
            'companies_actions_id' => $companyActionParent->getId(),
            'message_id' => $createMessage->getId(),
            'slug' => $action,
            'entity_uuid' => $requestId,
            'pipelines_stages_id' => $stage->getId(),
        ]);

        return $engagement;
    }

    /**
     * @todo add test
     */
    public function continueEngagement(mixed $rootValue, array $request): Engagement
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $request = $request['input'];

        if (! ActionStatusEnum::validate($request['status'])) {
            throw new ValidationException('Invalid Engagement Status');
        }

        $lead = Lead::getByIdFromCompanyApp($request['lead_id'], $company, $app);
        $people = ! empty($request['people_id']) ? People::getByIdFromCompanyApp($request['people_id'], $company, $app) : $lead->people;
        $receiver = ! empty($request['receiver_id']) ? LeadReceiver::getByIdFromCompanyApp($request['receiver_id'], $company, $app) : ($lead->receiver ?? LeadReceiver::getDefault($company, $app));
        $requestId = $request['request_id'];
        $action = $request['action'];
        $checkListId = $request['task_id'] ?? 0;
        $source = $request['source'];
        $via = $request['via'] ?? 'copy';
        $data = $request['data'] ?? [];
        $status = $request['status'];

        $companyAction = CompanyAction::getByAction(
            Action::getBySlug($action, $company),
            $company,
            $app,
            $lead->branch
        );

        $engagementMessage = new EngagementMessage(
            data: $data,
            text: $data['text'] ?? '',
            verb: $action,
            status: $status,
            actionLink: $data['link'] ?? '',
            source: $source,
            linkPreview: $data['link_preview'] ?? '',
            engagementStatus: $status,
            visitorId: $requestId,
            hashtagVisited: $companyAction->name,
            userUuid: $user->uuid,
            contactUuid: $people->uuid,
            checkListId: $checkListId,
            preFill: [],
            via: $via,
        );

        $messageInput = [
            'message' => $engagementMessage->toArray(),
            'reactions_count' => 0,
            'comments_count' => 0,
            'total_liked' => 0,
            'total_disliked' => 0,
            'total_saved' => 0,
            'total_shared' => 0,
            'ip_address' => request()->ip(),
        ];

        $messageTypeDto = MessageTypeInput::from([
            'apps_id' => $app->getId(),
            'name' => $action,
            'verb' => $action,
        ]);
        $messageType = (new CreateMessageTypeAction($messageTypeDto))->execute();

        $createMessage = (new CreateMessageAction(
            MessageInput::fromArray(
                $messageInput,
                $user,
                $messageType,
                $company,
                $app
            ),
            SystemModulesRepository::getByModelName(Lead::class, $app),
            $lead->getId()
        ))->execute();

        $pipeline = Pipeline::getBySlug($action, $app, $company);
        $stage = $pipeline->stages()->where('slug', $status)->firstOrFail();
        $channel = new CreateChannelAction(new Channel(
            apps: $app,
            companies: $lead->company,
            users: $lead->user,
            entity_id: $lead->getId(),
            entity_namespace: Lead::class,
            name: $lead->uuid,
            description: $lead->uuid,
            slug: $lead->uuid,
        ))->execute();
        if ($channel) {
            $channel->addMessage($createMessage, $user);
        }

        //save share history en company action history
        //generate link
        //create msg
        //create engagement
        //return engagement
        $engagement = Engagement::firstOrCreate([
            'companies_id' => $company->getId(),
            'apps_id' => $app->getId(),
            'users_id' => $user->getId(),
            'leads_id' => $lead->getId(),
            'people_id' => $people->getId(),
            'companies_actions_id' => $companyAction->getId(),
            'message_id' => $createMessage->getId(),
            'slug' => $action,
            'entity_uuid' => $requestId,
            'pipelines_stages_id' => $stage->getId(),
        ]);

        return $engagement;
    }

    private function getActionInfo(AppInterface $app, string $childSlug): array
    {
        $actionMappings = $app->get('sub-action-mappings');
        $result = [
            'parent' => $childSlug, // Default to original slug if not found
            'form_type' => null,     // Default to null if not found
        ];

        if (empty($actionMappings)) {
            return $result;
        }

        foreach ($actionMappings as $group => $mappings) {
            if (array_key_exists($childSlug, $mappings)) {
                $result['parent'] = $mappings[$childSlug]['parent'];

                if (isset($mappings[$childSlug]['form_type'])) {
                    $result['form_type'] = $mappings[$childSlug]['form_type'];
                }

                break;
            }
        }

        return $result;
    }
}
