<?php

declare(strict_types=1);

namespace App\GraphQL\ActionEngine\Mutations\Engagements;

use Baka\Support\Url;
use Kanvas\ActionEngine\Actions\Models\Action;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Actions\Models\CompanyActionVisitor;
use Kanvas\ActionEngine\Engagements\DataTransferObject\EngagementMessage;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\ActionEngine\Pipelines\Models\Pipeline;
use Kanvas\Apps\Models\Apps;
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
    public function startEngagement(mixed $rootValue, array $request): Engagement
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $request = $request['input'];

        $lead = Lead::getByIdFromCompanyApp($request['lead_id'], $company, $app);
        $people = ! empty($request['people_id']) ?
            People::getByIdFromCompanyApp($request['people_id'], $company, $app) :
            $lead->people;

        $receiver = ! empty($request['receiver_id']) ?
            LeadReceiver::getByIdFromCompanyApp($request['receiver_id'], $company, $app) :
            ($lead->receiver ?? LeadReceiver::getDefault($company, $app));

        $companyAction = CompanyAction::getByAction(
            Action::getBySlug($request['action'], $company),
            $company,
            $app,
            $lead->branch
        );

        $companyActionVisitor = CompanyActionVisitor::create([
            'visitors_id' => $request['request_id'],
            'leads_id' => $lead->uuid,
            'receivers_id' => $receiver->uuid,
            'contacts_id' => $people->uuid,
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'companies_actions_id' => $companyAction->getId(),
            'actions_slug' => $request['action'],
            'request' => $request,
        ]);

        $actionPageUrl = $this->getActionPageUrl($request['action'], $app);
        $url = $this->generateUrl($actionPageUrl, $request, $companyAction);

        $messageData = $this->generateMessageData($request, $user, $lead, $companyAction, $url, $app);
        $engagementMessage = new EngagementMessage(
            data: $request['data'] ?? [],
            text: $messageData['message_content']['ENG'],
            verb: $request['action'],
            status: ActionStatusEnum::SENT->value,
            actionLink: $messageData['link'],
            source: $request['source'],
            linkPreview: $messageData['link_preview'],
            engagementStatus: ActionStatusEnum::SENT->value,
            visitorId: $request['request_id'],
            hashtagVisited: $companyAction->name,
            userUuid: $user->uuid,
            contactUuid: $people->uuid,
            checkListId: $request['task_id'] ?? 0,
            preFill: [],
            via: $request['via'] ?? 'copy',
        );

        $messageType = (new CreateMessageTypeAction(MessageTypeInput::from([
            'apps_id' => $app->getId(),
            'name' => $request['action'],
            'verb' => $request['action'],
        ])))->execute();

        $createMessage = (new CreateMessageAction(
            MessageInput::fromArray($this->generateMessageInput($engagementMessage, $user), $user, $messageType, $company, $app),
            SystemModulesRepository::getByModelName(Lead::class, $app),
            $lead->getId()
        ))->execute();

        $pipelineStage = $this->getPipelineStage($request['action'], $app, $company);

        return Engagement::firstOrCreate([
            'companies_id' => $company->getId(),
            'apps_id' => $app->getId(),
            'users_id' => $user->getId(),
            'leads_id' => $lead->getId(),
            'people_id' => $people->getId(),
            'companies_actions_id' => $companyAction->getId(),
            'message_id' => $createMessage->getId(),
            'slug' => $request['action'],
            'entity_uuid' => $request['request_id'],
            'pipelines_stages_id' => $pipelineStage->getId(),
        ]);
    }

    private function getActionPageUrl(string $action, Apps $app): string
    {
        return in_array($action, $app->get('new-action-slug') ?? []) ?
            $app->get('NEW_LANDING_PAGE') :
            $app->get('TEMP_LANDING_PAGE');
    }

    private function generateUrl(string $actionPageUrl, array $request, CompanyAction $companyAction): string
    {
        $params = array_intersect_key(
            $request,
            array_flip([
                'visitors_id', 'visitor_id', 'users_id', 'vehicle_id', 'leads_id',
                'lead_id', 'receivers_id', 'receiver_id', 'contacts_id', 'actions_slug',
                'cid', 'bcid', 'form_type',
            ])
        );

        $extraField = is_array($request['extraField'] ?? null) ? implode('&', $request['extraField']) : null;

        return $actionPageUrl . "/{$request['action']}?" . http_build_query($params) . $extraField;
    }

    private function generateMessageData(array $request, $user, $lead, CompanyAction $companyAction, string $url, Apps $app): array
    {
        return [
            'link' => Url::getShortUrl($url, $app),
            'link_preview' => Url::getShortUrl("{$url}&preview=true", $app),
            'data' => $companyAction->form_config,
            'message_content' => [
                'ENG' => sprintf(
                    'Hi {name}, this is %s from %s. Click the link below to %s.',
                    $user->firstname,
                    $lead->branch->name ?? 'our team',
                    $companyAction->get('reasonEn') ?? 'continue'
                ),
                'ES' => sprintf(
                    'Hola {name}, es %s de %s. Haz click al siguiente enlace para %s.',
                    $user->firstname,
                    $lead->branch->name ?? 'nuestro equipo',
                    $companyAction->get('reasonEs') ?? 'continuar'
                ),
            ],
        ];
    }

    private function generateMessageInput(EngagementMessage $engagementMessage, $user): array
    {
        return [
            'message' => $engagementMessage->toArray(),
            'reactions_count' => 0,
            'comments_count' => 0,
            'total_liked' => 0,
            'total_disliked' => 0,
            'total_saved' => 0,
            'total_shared' => 0,
            'ip_address' => request()->ip(),
        ];
    }

    private function getPipelineStage(string $action, Apps $app, $company)
    {
        $pipeline = Pipeline::getBySlug($action, $app, $company);

        return $pipeline->stages()
            ->where('slug', ActionStatusEnum::SENT->value)
            ->firstOrFail();
    }
}
