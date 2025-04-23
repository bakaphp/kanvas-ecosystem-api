<?php

declare(strict_types=1);

namespace App\GraphQL\ActionEngine\Mutations\Engagements;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Baka\Support\Url;
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
        $people = ! empty($request['people_id']) ? People::getByIdFromCompanyApp($request['people_id'], $company, $app) : $lead->people;
        $receiver = ! empty($request['receiver_id']) ? LeadReceiver::getByIdFromCompanyApp($request['receiver_id'], $company, $app) : ($lead->receiver ?? LeadReceiver::getDefault($company, $app));
        $requestId = $request['request_id'];
        $parentAction = $this->getActionInfo($app, $request['action']);
        $action = $parentAction['parent'];

        $checkListId = $request['task_id'] ?? 0;
        $source = $request['source'];
        $via = $request['via'] ?? 'copy';
        $data = $request['data'] ?? [];

        $companyAction = CompanyAction::getByAction(
            Action::getBySlug($action, $company),
            $company,
            $app,
            $lead->branch
        );

        /**
         * @todo clean this
         */
        $newActionPageUrl = in_array($action, $app->get('new-action-slug') ?? []);
        $actionPageUrl = ! $newActionPageUrl ? $app->get('TEMP_LANDING_PAGE') : $app->get('NEW_LANDING_PAGE');

        $request['visitors_id'] = $requestId;
        $request['visitor_id'] = $request['visitors_id'];
        $request['users_id'] = $user->getId();
        $request['leads_id'] = $lead->uuid;
        $request['lead_id'] = $lead->uuid;
        $request['vehicle_id'] = null;
        $request['receivers_id'] = $receiver->uuid;
        $request['receiver_id'] = $receiver->uuid;
        //$request['receiver_id'] = $request['receiver_id'];
        $request['contacts_id'] = $people->uuid;
        $request['request'] = json_encode($request);
        $request['actions_slug'] = $action;
        $request['cid'] = $lead->company->uuid;
        $request['bcid'] = $lead->branch ? $lead->branch->uuid : null;

        if (! empty($parentAction['form_type'])) {
            $request['form_type'] = $parentAction['form_type'];
        }

        $extraField = ! empty($request['extraField']) ? $request['extraField'] : null;
        $companyActionId = $request['company_action_id'] ?? null;

        if (is_array($extraField)) {
            $extraField = implode('&', $extraField);
        }

        $companyActionVisitor = CompanyActionVisitor::create([
            'visitors_id'          => $request['request_id'],
            'leads_id'             => $lead->uuid,
            'receivers_id'         => $receiver->uuid,
            'contacts_id'          => $people->uuid,
            'companies_id'         => $company->getId(),
            'users_id'             => $user->getId(),
            'companies_actions_id' => $companyAction->getId(),
            'actions_slug'         => $request['action'],
            'request'              => $request,
        ]);

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
        $urlParams = http_build_query($params).$extraField;
        $urlParams .= '&caction='.$companyAction->uuid;

        $url = $actionPageUrl."/{$action}?{$urlParams}";
        $urlPreview = $actionPageUrl."/{$action}?{$urlParams}&preview=true";

        $reasonEnglish = $companyAction->get('reasonEn');
        $reasonSpanish = $companyAction->get('reasonEs');

        $messageEnglish = 'Hi {name}, this is '.$user->firstname.' from '.$lead->branch->name.'. Click the link below to '.$reasonEnglish;
        $messageSpanish = 'Hola {name}, es '.$user->firstname.' de '.$lead->branch->name.'. Haz click al siguiente enlace para '.$reasonSpanish;

        $messageData = [
            'link'              => Url::getShortUrl($url, $app),
            'link_preview'      => Url::getShortUrl($urlPreview, $app),
            'link_full'         => $url,
            'link_full_preview' => $urlPreview,
            'data'              => $companyAction->form_config,
            'params'            => $request,
            'preview_image'     => null,
            'message_content'   => [
                'ENG' => $reasonEnglish !== null && Str::endsWith($reasonEnglish, '!') ? $messageEnglish : $messageEnglish.'. ',
                'ES'  => $reasonSpanish !== null && Str::endsWith($reasonSpanish, '!') ? $messageSpanish : $messageSpanish.'. ',
            ],
        ];

        $engagementMessage = new EngagementMessage(
            data: $data,
            text: $messageEnglish,
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
        );
        $messageInput = [
            'message'         => $engagementMessage->toArray(),
            'reactions_count' => 0,
            'comments_count'  => 0,
            'total_liked'     => 0,
            'total_disliked'  => 0,
            'total_saved'     => 0,
            'total_shared'    => 0,
            'ip_address'      => request()->ip(),
        ];

        $messageTypeDto = MessageTypeInput::from([
            'apps_id' => $app->getId(),
            'name'    => $action,
            'verb'    => $action,
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

        $leadChannel = $lead->socialChannel?->count() ? $lead->socialChannel->first() : null;
        if ($leadChannel) {
            $leadChannel->addMessage($createMessage, $user);
        }

        //save share history en company action history
        //generate link
        //create msg
        //create engagement
        //return engagement
        $engagement = Engagement::firstOrCreate([
            'companies_id'         => $company->getId(),
            'apps_id'              => $app->getId(),
            'users_id'             => $user->getId(),
            'leads_id'             => $lead->getId(),
            'people_id'            => $people->getId(),
            'companies_actions_id' => $companyAction->getId(),
            'message_id'           => $createMessage->getId(),
            'slug'                 => $action,
            'entity_uuid'          => $requestId,
            'pipelines_stages_id'  => $stage->getId(),
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
            'message'         => $engagementMessage->toArray(),
            'reactions_count' => 0,
            'comments_count'  => 0,
            'total_liked'     => 0,
            'total_disliked'  => 0,
            'total_saved'     => 0,
            'total_shared'    => 0,
            'ip_address'      => request()->ip(),
        ];

        $messageTypeDto = MessageTypeInput::from([
            'apps_id' => $app->getId(),
            'name'    => $action,
            'verb'    => $action,
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
        $leadChannel = $lead->socialChannel?->count() ? $lead->socialChannel->first() : null;
        if ($leadChannel) {
            $leadChannel->addMessage($createMessage, $user);
        }

        //save share history en company action history
        //generate link
        //create msg
        //create engagement
        //return engagement
        $engagement = Engagement::firstOrCreate([
            'companies_id'         => $company->getId(),
            'apps_id'              => $app->getId(),
            'users_id'             => $user->getId(),
            'leads_id'             => $lead->getId(),
            'people_id'            => $people->getId(),
            'companies_actions_id' => $companyAction->getId(),
            'message_id'           => $createMessage->getId(),
            'slug'                 => $action,
            'entity_uuid'          => $requestId,
            'pipelines_stages_id'  => $stage->getId(),
        ]);

        return $engagement;
    }

    private function getActionInfo(AppInterface $app, string $childSlug): array
    {
        $actionMappings = $app->get('sub-action-mappings');
        $result = [
            'parent'    => $childSlug, // Default to original slug if not found
            'form_type' => null,     // Default to null if not found
        ];

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
