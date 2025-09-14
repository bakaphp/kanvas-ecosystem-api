<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Engagements\Actions;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Baka\Support\Url;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Kanvas\ActionEngine\Actions\Enums\ActionEnum;
use Kanvas\ActionEngine\Actions\Models\Action;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Actions\Models\CompanyActionVisitor;
use Kanvas\ActionEngine\Engagements\DataTransferObject\Engagement as EngagementData;
use Kanvas\ActionEngine\Engagements\DataTransferObject\EngagementMessage;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\ActionEngine\Pipelines\Models\Pipeline;
use Kanvas\ActionEngine\Tasks\Models\TaskListItem;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Stripe\Services\StripePaymentLinkService;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel;
use Kanvas\Social\Channels\Models\Channel as ModelsChannel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\WorkflowEnum;

class CreateEngagementAction
{
    protected Lead $lead;
    protected People $people;
    protected LeadReceiver $receiver;
    protected CompanyAction $companyAction;
    protected CompanyAction $companyActionParent;
    protected string $actionSlug;
    protected array $messageData = [];
    protected string|int|null $checkListId = null;
    protected Apps $app;
    protected Companies $company;
    protected Users $user;
    public bool $runWorkflow = true;

    public function __construct(
        protected EngagementData $engagementData,
        protected bool $allowDuplicate = true
    ) {
        $this->app = $engagementData->app;
        $this->company = $engagementData->company;
        $this->user = $engagementData->user;

        // Load lead and related data
        $this->lead = $engagementData->lead;
        $this->user->follow($this->lead);
        $this->people = $engagementData->people ?? $this->lead->people;
        $this->prepareData();
        $this->resolveAction();
        if ($engagementData->status === ActionStatusEnum::SENT) {
            $this->handleLinkGeneration();
        }
    }

    public function execute(): Engagement
    {
        if (! $this->allowDuplicate) {
            $existingEngagement = $this->getLastOpenEngagementWithoutSubmission();
            if ($existingEngagement) {
                return $existingEngagement;
            }
        }

        return DB::transaction(function () {
            $this->createCompanyActionVisitor();

            $channel = $this->createOrGetChannel();
            $message = $this->createMessage();

            if ($channel) {
                $channel->addMessage($message, $this->user);
            }

            $engagement = $this->createEngagement($message);

            if ($this->runWorkflow) {
                $engagement->fireWorkflow(
                    WorkflowEnum::CREATED->value,
                    true,
                    [
                        'message' => $message,
                        'channel' => $channel,
                    ]
                );
            }

            return $engagement;
        });
    }

    private function handleLinkGeneration(): void
    {
        if ($this->allowDuplicate) {
            $this->generateUrls();

            return;
        }

        $previousLinkKey = 'engagement_previous_link';
        $verifyPreviousLink = $this->lead->get($previousLinkKey) ?? [];
        $hasPreviousLinkData = ! empty($verifyPreviousLink) && is_array($verifyPreviousLink);
        $actionExists = $hasPreviousLinkData && array_key_exists($this->engagementData->action, $verifyPreviousLink);

        if (! $actionExists) {
            $this->generateUrls();
            $verifyPreviousLink[$this->engagementData->action] = $this->messageData;
            $this->lead->set($previousLinkKey, $verifyPreviousLink);
        } else {
            $this->messageData = $verifyPreviousLink[$this->engagementData->action];
        }
    }

    protected function prepareData(): void
    {
        // Load receiver
        $this->receiver = $this->engagementData->receiverId !== null
            ? LeadReceiver::getByIdFromCompanyApp($this->engagementData->receiverId, $this->company, $this->app)
            : ($this->lead->receiver ?? LeadReceiver::getDefault($this->company, $this->app));

        // Handle checklist task
        if ($this->engagementData->taskId !== null) {
            try {
                $checkListTaskItem = TaskListItem::getById($this->engagementData->taskId);
                if ($checkListTaskItem->task->company->getId() === $this->lead->company->getId()) {
                    $this->checkListId = $checkListTaskItem->task->getId();
                }
            } catch (ExceptionsModelNotFoundException|ModelNotFoundException $e) {
            }
        }
    }

    protected function resolveAction(): void
    {
        $this->actionSlug = $this->engagementData->action;
        $parentAction = $this->getActionInfo($this->app, $this->engagementData->action);
        $resolvedActionSlug = $parentAction['parent'];

        // Action type mappings, @todo move this to be dynamic per app
        $actionMappings = [
            'creditApp' => [
                ActionEnum::CREDIT_APP_2->value,
                ActionEnum::CREDIT_APP_3->value,
                ActionEnum::CREDIT_APP_4->value,
                ActionEnum::CREDIT_APP_5->value,
                ActionEnum::CREDIT_APP_6->value,
                ActionEnum::CREDIT_APP_7->value,
            ],
            'cosigner' => [
                ActionEnum::CO_SIGNER_2->value,
                ActionEnum::CO_SIGNER_3->value,
                ActionEnum::CO_SIGNER_4->value,
                ActionEnum::CO_SIGNER_5->value,
            ],
            'codeShare' => [
                ActionEnum::SHARE_BLUELINK->value,
                ActionEnum::SHARE_ELECTRIFY_AMERICA->value,
            ],
        ];

        // Handle special action types
        foreach ($actionMappings as $type => $actions) {
            if (in_array($this->actionSlug, $actions)) {
                $this->engagementData->extraData["mixed_{$type}_app"] = $this->actionSlug;
                $resolvedActionSlug = $this->getBaseAction($type, $this->actionSlug);

                if ($formType = $this->getFormType($type, $this->actionSlug)) {
                    $this->engagementData->formType = $formType;
                }

                break;
            }
        }

        $action = Action::getBySlug($resolvedActionSlug, $this->company);
        if (! $action) {
            throw new ModelNotFoundException('Action not found');
        }
        // Get company action
        $this->companyAction = CompanyAction::getByAction(
            $action,
            $this->company,
            $this->app,
            $this->lead->branch
        );

        // Set parent action for special cases
        $this->companyActionParent = $this->companyAction;
        if (isset($this->engagementData->extraData['mixed_creditApp_app']) || isset($this->engagementData->extraData['mixed_cosigner_app'])) {
            $this->companyActionParent = CompanyAction::getByAction(
                $action,
                $this->company,
                $this->app,
                $this->lead->branch
            );
        }
    }

    /**
     * prepare the engagement url , will only work on sent
     */
    protected function generateUrls(): void
    {
        $newActionPageUrl = in_array($this->actionSlug, $this->app->get('new-action-slug') ?? []);
        $actionPageUrl = ! $newActionPageUrl ? $this->app->get('TEMP_LANDING_PAGE') : $this->app->get('NEW_LANDING_PAGE');

        $params = [
            'visitors_id' => $this->engagementData->requestId,
            'visitor_id' => $this->engagementData->requestId,
            'users_id' => $this->user->getId(),
            'vehicle_id' => null,
            'leads_id' => $this->lead->uuid,
            'lead_id' => $this->lead->uuid,
            'receivers_id' => $this->receiver->uuid,
            'receiver_id' => $this->receiver->uuid,
            'contacts_id' => $this->people->uuid,
            'actions_slug' => $this->actionSlug,
            'cid' => $this->lead->company->uuid,
            'bcid' => $this->lead->branch ? $this->lead->branch->uuid : null,
            'form_type' => $this->engagementData->formType,
        ];

        $extraField = $this->engagementData->extraField;
        if (is_array($extraField)) {
            $extraField = implode('&', $extraField);
        }

        $urlParams = http_build_query(array_filter($params)) . ($extraField ? '&' . $extraField : '');
        $urlParams .= '&caction=' . $this->companyAction->uuid;

        // Add company language if set
        $companyLanguage = $this->lead->company->get('COMPANY_MULTI_LANGUAGE');
        if ($companyLanguage) {
            $urlParams .= '&lang=' . $companyLanguage;
        }

        $url = $actionPageUrl . "/{$this->actionSlug}?{$urlParams}";
        $urlPreview = $actionPageUrl . "/{$this->actionSlug}?{$urlParams}&preview=true";

        // Generate message content
        $reasonEnglish = $this->companyAction->get('reasonEn');
        $reasonSpanish = $this->companyAction->get('reasonEs');

        $messageEnglish = 'Hi {name}, this is ' . $this->user->firstname . ' from ' . $this->lead->branch->name . '. Click the link below to ' . $reasonEnglish;
        $messageSpanish = 'Hola {name}, es ' . $this->user->firstname . ' de ' . $this->lead->branch->name . '. Haz click al siguiente enlace para ' . $reasonSpanish;

        $this->messageData = [
            'link' => Url::getShortUrl($url, $this->app),
            'link_preview' => Url::getShortUrl($urlPreview, $this->app),
            'link_full' => $url,
            'link_full_preview' => $urlPreview,
            'data' => $this->companyAction->form_config,
            'params' => array_merge($params, $this->engagementData->extraData),
            'preview_image' => null,
            'message_content' => [
                'ENG' => $reasonEnglish !== null && Str::endsWith($reasonEnglish, '!') ? $messageEnglish : $messageEnglish . '. ',
                'ES' => $reasonSpanish !== null && Str::endsWith($reasonSpanish, '!') ? $messageSpanish : $messageSpanish . '. ',
            ],
        ];
    }

    protected function createCompanyActionVisitor(): CompanyActionVisitor
    {
        if (! $this->allowDuplicate) {
            $existingVisitor = CompanyActionVisitor::where('visitors_id', $this->engagementData->requestId)
                ->where('leads_id', $this->lead->uuid)
                ->where('companies_actions_id', $this->companyActionParent->getId())
                ->first();

            if ($existingVisitor) {
                return $existingVisitor;
            }
        }

        return CompanyActionVisitor::create([
            'visitors_id' => $this->engagementData->requestId,
            'leads_id' => $this->lead->uuid,
            'receivers_id' => $this->receiver->uuid,
            'contacts_id' => $this->people->uuid,
            'companies_id' => $this->company->getId(),
            'users_id' => $this->user->getId(),
            'companies_actions_id' => $this->companyActionParent->getId(),
            'actions_slug' => $this->engagementData->action,
            'request' => array_merge($this->engagementData->toArray(), ($this->messageData['params'] ?? [])),
        ]);
    }

    protected function createMessage(): Message
    {
        $engagementMessage = new EngagementMessage(
            data: $this->engagementData->data,
            text: $this->companyAction->name,
            verb: $this->actionSlug,
            status: $this->engagementData->status->value,
            actionLink: $this->messageData['link'] ?? '',
            source: $this->engagementData->source,
            linkPreview: $this->messageData['link_preview'] ?? '',
            engagementStatus: $this->engagementData->status->value,
            visitorId: $this->engagementData->requestId,
            hashtagVisited: $this->companyAction->name,
            userUuid: $this->user->uuid,
            contactUuid: $this->people->uuid,
            checkListId: $this->checkListId,
            preFill: [],
            via: $this->engagementData->via,
            product_id: $this->engagementData->data['product_id'] ?? null,
            channel_id: $this->engagementData->data['channel_id'] ?? null,
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
            'apps_id' => $this->app->getId(),
            'name' => $this->actionSlug,
            'verb' => $this->actionSlug,
        ]);
        $messageType = (new CreateMessageTypeAction($messageTypeDto))->execute();

        $message = (new CreateMessageAction(
            MessageInput::fromArray(
                $messageInput,
                $this->user,
                $messageType,
                $this->company,
                $this->app
            ),
            SystemModulesRepository::getByModelName(Lead::class, $this->app),
            $this->lead->getId()
        ))->execute();

        //@todo move this to a workflow activity (Async)
        $this->replaceLink(
            $this->lead,
            $message,
            $this->actionSlug,
            $this->engagementData->data
        );

        return $message;
    }

    protected function createOrGetChannel(): ModelsChannel
    {
        return (new CreateChannelAction(new Channel(
            apps: $this->app,
            companies: $this->lead->company,
            users: $this->lead->user,
            entity_id: $this->lead->getId(),
            entity_namespace: Lead::class,
            name: $this->lead->uuid,
            slug: $this->lead->uuid,
            description: $this->lead->uuid,
        )))->execute();
    }

    /**
     * Get the last sent engagement that hasn't been submitted
     * This will only work if allowDuplicate is false
     */
    protected function getLastOpenEngagementWithoutSubmission(): ?Engagement
    {
        if ($this->allowDuplicate) {
            return null;
        }

        $pipeline = Pipeline::getBySlug($this->actionSlug, $this->app, $this->company);
        $sentStage = $pipeline->stages()->where('slug', ActionStatusEnum::SENT->value)->first();
        $submittedStage = $pipeline->stages()->where('slug', ActionStatusEnum::SUBMITTED->value)->first();

        if (! $sentStage || ! $submittedStage) {
            return null;
        }

        // First, get the last SENT engagement
        $lastSentEngagement = Engagement::fromCompany($this->company)
            ->fromApp($this->app)
            ->where('users_id', $this->user->getId())
            ->where('leads_id', $this->lead->getId())
            ->where('people_id', $this->people->getId())
            ->where('companies_actions_id', $this->companyActionParent->getId())
            ->where('pipelines_stages_id', $sentStage->getId())
            ->where('slug', $this->actionSlug)
            ->where('is_deleted', 0)
            ->orderBy('created_at', 'desc')
            ->first();

        if (! $lastSentEngagement) {
            return null;
        }

        // Check if there's a submitted engagement after this sent engagement
        $hasSubmittedEngagement = Engagement::fromCompany($this->company)
            ->fromApp($this->app)
            ->where('users_id', $this->user->getId())
            ->where('leads_id', $this->lead->getId())
            ->where('people_id', $this->people->getId())
            ->where('companies_actions_id', $this->companyActionParent->getId())
            ->where('pipelines_stages_id', $submittedStage->getId())
            ->where('slug', $this->actionSlug)
            ->where('created_at', '>', $lastSentEngagement->created_at)
            ->exists();

        // If there's no submitted engagement after the last sent one, return it
        return $hasSubmittedEngagement ? null : $lastSentEngagement;
    }

    protected function createEngagement(Message $message): Engagement
    {
        $pipeline = Pipeline::getBySlug($this->actionSlug, $this->app, $this->company);
        $stage = $pipeline->stages()->where('slug', $this->engagementData->status->value)->firstOrFail();

        return Engagement::firstOrCreate([
            'companies_id' => $this->company->getId(),
            'apps_id' => $this->app->getId(),
            'users_id' => $this->user->getId(),
            'leads_id' => $this->lead->getId(),
            'people_id' => $this->people->getId(),
            'companies_actions_id' => $this->companyActionParent->getId(),
            'message_id' => $message->getId(),
            'slug' => $this->actionSlug,
            'entity_uuid' => $this->engagementData->requestId,
            'pipelines_stages_id' => $stage->getId(),
        ]);
    }

    protected function getActionInfo(AppInterface $app, string $childSlug): array
    {
        $actionMappings = $app->get('sub-action-mappings');
        $result = [
            'parent' => $childSlug,
            'form_type' => null,
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

    /**
     * @todo remove this legacy hardcoded actions
     */
    protected function getBaseAction(string $type, string $actionSlug): string
    {
        return match ($type) {
            'creditApp' => ActionEnum::CREDIT_APP->value,
            'cosigner' => ActionEnum::CO_SIGNER->value,
            'codeShare' => ActionEnum::SHARE_BLUELINK->value,
            default => $actionSlug,
        };
    }

    /**
     * @todo remove this legacy hardcoded actions
     */
    protected function getFormType(string $type, string $actionSlug): ?string
    {
        $formTypes = [
            'creditApp' => [
                ActionEnum::CREDIT_APP_2->value => 'finance-lease',
                ActionEnum::CREDIT_APP_3->value => 'personal-check',
                ActionEnum::CREDIT_APP_4->value => 'cashier-check',
                ActionEnum::CREDIT_APP_5->value => 'all-cash',
                ActionEnum::CREDIT_APP_6->value => '5-liner',
                ActionEnum::CREDIT_APP_7->value => 'finance',
            ],
            'cosigner' => [
                ActionEnum::CO_SIGNER_2->value => 'finance-lease',
                ActionEnum::CO_SIGNER_3->value => 'personal-check',
                ActionEnum::CO_SIGNER_4->value => 'cashier-check',
                ActionEnum::CO_SIGNER_5->value => 'all-cash',
            ],
        ];

        return $formTypes[$type][$actionSlug] ?? null;
    }

    protected function replaceLink(
        Lead $lead,
        Message $message,
        string $action,
        array $data
    ): void {
        if ($action === 'get-deposit' && isset($data['amount']) && (float) $data['amount'] > 0) {
            $stripeCheckout = new StripePaymentLinkService($lead->app, $lead->company);
            $paymentLink = $stripeCheckout->generatePaymentLinkFromLeadMessage($lead, $message, []);

            $messageData = $message->message ?? [];
            $paymentLinkFullLink = $paymentLink->url;
            if ($lead->people_id && $lead->people && $email = $lead->people->getEmails()->first()?->value) {
                $paymentLinkFullLink .= '?prefilled_email=' . urlencode($email);
            }
            $paymentShortLink = Url::getShortUrl($paymentLinkFullLink, $lead->app);

            $messageData['action_link'] = $paymentShortLink;
            $messageData['preview_link'] = $paymentShortLink;
            $message->message = $messageData;
            $message->saveOrFail();
        }
    }

    /**
     * Disable workflow execution
     */
    public function withoutWorkflow(): self
    {
        $this->runWorkflow = false;

        return $this;
    }
}
