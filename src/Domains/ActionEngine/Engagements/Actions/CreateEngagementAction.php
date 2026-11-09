<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Engagements\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\EngageableInterface;
use Baka\Support\IPInfo;
use Baka\Support\Str;
use Baka\Support\Url;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Kanvas\ActionEngine\Actions\Enums\ActionEnum;
use Kanvas\ActionEngine\Actions\Models\Action;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Actions\Models\CompanyActionVisitor;
use Kanvas\ActionEngine\Engagements\DataTransferObject\Engagement as EngagementData;
use Kanvas\ActionEngine\Engagements\DataTransferObject\EngagementMessage;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Engagements\Support\EngagementReferenceMap;
use Kanvas\ActionEngine\Engagements\Support\LeadEngagementHooks;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\ActionEngine\Pipelines\Models\Pipeline;
use Kanvas\ActionEngine\Tasks\Models\TaskListItem;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\ShortIo\Services\ShortIoConfigurationService;
use Kanvas\Connectors\Stripe\Services\StripePaymentLinkService;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;
use Kanvas\Filesystem\Models\Filesystem;
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
    protected ?Lead $lead = null;
    protected EngageableInterface $reference;
    protected ?LeadEngagementHooks $leadHooks = null;
    protected People $people;
    protected ?LeadReceiver $receiver = null;
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
        protected bool $allowDuplicate = true,
        protected bool $requireActiveAction = false
    ) {
        $this->app = $engagementData->app;
        $this->company = $engagementData->company;
        $this->user = $engagementData->user;

        $this->reference = $engagementData->getReference();
        $this->lead = $this->reference instanceof Lead ? $this->reference : null;

        if ($this->lead !== null) {
            $this->leadHooks = new LeadEngagementHooks($this->lead, $engagementData);
            $this->leadHooks->follow($this->user);
        }

        $this->people = $engagementData->people
            ?? $this->lead?->people
            ?? throw new InvalidArgumentException('An engagement needs a person.');

        if ($this->lead === null) {
            $this->assertSameTenant();
        }

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
            $newLink = $this->generateNewEngagementUrl($engagement);

            //update msg new action page migration link
            if ($newLink !== null) {
                $messageData = $message->message ?? [];
                $messageData['action_link'] = Url::getShortUrl($newLink, $this->app, company: $engagement->company);
                $message->message = $messageData;
                $message->saveOrFail();
                $engagement->refresh();
            }

            if ($this->runWorkflow) {
                $this->fireCreatedWorkflow($engagement, $message, $channel);
            }

            return $engagement;
        });
    }

    protected function fireCreatedWorkflow(Engagement $engagement, Message $message, ModelsChannel $channel): void
    {
        $engagement->fireWorkflow(
            WorkflowEnum::CREATED->value,
            true,
            $this->createdWorkflowParams($engagement, $message, $channel)
        );
    }

    /**
     * entity_type lets a rule route by reference ("engagement on a scribe_invoice → AR agent").
     */
    protected function createdWorkflowParams(Engagement $engagement, Message $message, ModelsChannel $channel): array
    {
        return [
            'message' => $message,
            'channel' => $channel,
            'entity_type' => $engagement->entity_type,
        ];
    }

    /**
     * A non-lead reference gets its link from generateNewEngagementUrl() once the engagement exists;
     * the legacy landing-page link is built from lead params.
     */
    private function handleLinkGeneration(): void
    {
        if ($this->leadHooks === null) {
            return;
        }

        if ($this->allowDuplicate) {
            $this->generateUrls();

            return;
        }

        $this->messageData = $this->leadHooks->rememberedLinkMessageData(function (): array {
            $this->generateUrls();

            return $this->messageData;
        });
    }

    /**
     * Same-tenant is a given on the lead path (the lead is the tenant); any other reference, and the
     * person it is shared with, must sit in the engagement's app and company.
     */
    protected function assertSameTenant(): void
    {
        $sameApp = (int) $this->reference->getEngagementApp()->getId() === (int) $this->app->getId();
        $sameCompany = (int) $this->reference->getEngagementCompany()->getId() === (int) $this->company->getId();
        $personInCompany = (int) $this->people->companies_id === (int) $this->company->getId();

        if (! $sameApp || ! $sameCompany || ! $personInCompany) {
            throw new InvalidArgumentException('The engagement reference and person must belong to the engagement app and company.');
        }
    }

    protected function prepareData(): void
    {
        $this->receiver = $this->leadHooks?->receiver();

        // Handle checklist task
        if ($this->engagementData->taskId !== null) {
            try {
                $checkListTaskItem = TaskListItem::getById($this->engagementData->taskId);
                if ($checkListTaskItem->task->company->getId() === $this->reference->getEngagementCompany()->getId()) {
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
                'business-credit-app-1',
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
            'messageVideos' => [
                ActionEnum::MESSAGE_VIDEO->value,
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
            throw $this->requireActiveAction
                ? $this->unavailableActionException()
                : new ModelNotFoundException('Action not found');
        }

        try {
            $this->companyAction = CompanyAction::getByAction(
                $action,
                $this->company,
                $this->app,
                $this->leadHooks?->branch()
            );
        } catch (ModelNotFoundException $e) {
            throw $this->requireActiveAction ? $this->unavailableActionException() : $e;
        }

        // Checked on the resolved row so a variant slug (credit-app-3) is gated by its base Sales App.
        if ($this->requireActiveAction && ! $this->companyAction->is_active) {
            throw $this->unavailableActionException();
        }

        // Set parent action for special cases
        $this->companyActionParent = $this->companyAction;
        if (isset($this->engagementData->extraData['mixed_creditApp_app']) || isset($this->engagementData->extraData['mixed_cosigner_app'])) {
            $this->companyActionParent = CompanyAction::getByAction(
                $action,
                $this->company,
                $this->app,
                $this->leadHooks?->branch()
            );
        }
    }

    protected function unavailableActionException(): InvalidArgumentException
    {
        return new InvalidArgumentException("The {$this->actionSlug} Sales App is not available for this company.");
    }

    protected function generateNewEngagementUrl(Engagement $engagement): ?string
    {
        $checkoutActions = $this->app->get('new-action-checkout-link') ?? [];
        $newActionPageV4 = $engagement->company->get('new_action_page_v4_config') ?? $this->app->get('new_action_page_v4_config') ?? [];
        $isCheckoutAction = is_array($checkoutActions) && in_array($this->actionSlug, $checkoutActions);

        $isNewActionPage = false;
        $actionPathV4 = null;

        if (is_array($newActionPageV4)) {
            if (array_key_exists($this->actionSlug, $newActionPageV4)) {
                $isNewActionPage = true;
                $actionPathV4 = $newActionPageV4[$this->actionSlug];
            } elseif (in_array($this->actionSlug, $newActionPageV4)) {
                $isNewActionPage = true;
            }
        }

        if ($isCheckoutAction) {
            return (string) $this->app->get('NEW_CHECKOUT_PAGE') . '/' . $engagement->uuid;
        }

        if ($isNewActionPage) {
            $baseDomain = ShortIoConfigurationService::linkPageBaseUrl($this->app, $engagement->company);
            $actionPath = $actionPathV4 ?: 'form';

            return $baseDomain . '/' . ltrim((string) $actionPath, '/') . '/' . $engagement->uuid;
        }

        $landingPageActions = $this->app->get('new-action-slug-v3') ?? [];
        $isLandingPageAction = is_array($landingPageActions) && in_array($this->actionSlug, $landingPageActions);

        if ($isLandingPageAction) {
            return (string) $this->app->get('NEW_LANDING_PAGE_V3') . '/' . $engagement->uuid;
        }

        $messageVideoAction = $this->app->get('message-video-link') ?? [];
        $isMessageVideoAction = is_array($messageVideoAction) && in_array($this->actionSlug, $messageVideoAction);

        if ($isMessageVideoAction) {
            return (string) $this->app->get('MESSAGE_VIDEO_PAGE') . '/' . $engagement->uuid;
        }

        return null;
    }

    /**
     * prepare the engagement url , will only work on sent
     */
    protected function generateUrls(): void
    {
        $this->messageData = $this->leadHooks->linkMessageData(
            $this->receiver,
            $this->people,
            $this->actionSlug,
            $this->companyAction
        );
    }

    protected function createCompanyActionVisitor(): CompanyActionVisitor
    {
        if (! $this->allowDuplicate) {
            $existingVisitor = CompanyActionVisitor::where('visitors_id', $this->engagementData->requestId)
                ->where('leads_id', $this->visitorLeadId())
                ->where('companies_actions_id', $this->companyActionParent->getId())
                ->first();

            if ($existingVisitor) {
                return $existingVisitor;
            }
        }

        $request = array_merge(
            Arr::except($this->engagementData->toArray(), ['reference']),
            $this->messageData['params'] ?? []
        );

        if ($this->lead === null) {
            $request['entity_type'] = EngagementReferenceMap::aliasFor($this->reference);
            $request['entity_id'] = $this->reference->getId();
        }

        return CompanyActionVisitor::create([
            'visitors_id' => $this->engagementData->requestId,
            'leads_id' => $this->visitorLeadId(),
            'receivers_id' => $this->receiver !== null ? $this->receiver->uuid : '',
            'contacts_id' => $this->people->uuid,
            'companies_id' => $this->company->getId(),
            'users_id' => $this->user->getId(),
            'companies_actions_id' => $this->companyActionParent->getId(),
            'actions_slug' => $this->engagementData->action,
            'request' => $request,
        ]);
    }

    /**
     * companies_actions_visitors.leads_id is NOT NULL; a non-lead visit records its reference in
     * `request` instead.
     */
    private function visitorLeadId(): mixed
    {
        return $this->lead !== null ? $this->lead->uuid : '';
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
            taskItemId: $this->engagementData->taskId,
            preFill: [],
            via: $this->engagementData->via,
            product_id: $this->engagementData->data['product_id'] ?? null,
            channel_id: $this->engagementData->data['channel_id'] ?? null,
        );

        $data = $engagementMessage->toArray();

        // Who or what created this engagement internally (a slug, 'update-lead', ...). Written
        // straight onto the payload rather than declared on EngagementMessage: Spatie emits every
        // declared property
        if ($this->engagementData->from !== null) {
            $data['from'] = $this->engagementData->from;
        }

        if ($this->company->get('hide_millage')) {
            $data['data']['hide_price'] = true;
            $data['data']['hide_mileage'] = true;
        }

        $messageInput = [
            'message' => $data,
            'reactions_count' => 0,
            'comments_count' => 0,
            'total_liked' => 0,
            'total_disliked' => 0,
            'total_saved' => 0,
            'total_shared' => 0,
            'ip_address' => IPInfo::getClientIp(),
        ];

        //MessageInput::fromArray looks up the parent whenever the key exists, so only set it when there is one
        $parentMessageId = $this->engagementData->parentEngagement?->message_id;
        if ($parentMessageId !== null) {
            $messageInput['parent_id'] = $parentMessageId;
        }

        $messageTypeDto = MessageTypeInput::from([
            'apps_id' => $this->app->getId(),
            'name' => $this->actionSlug,
            'verb' => $this->actionSlug,
        ]);
        $messageType = new CreateMessageTypeAction($messageTypeDto)->execute();

        $message = new CreateMessageAction(
            MessageInput::fromArray(
                $messageInput,
                $this->user,
                $messageType,
                $this->company,
                $this->app
            ),
            SystemModulesRepository::getByModelName($this->reference::class, $this->app),
            $this->reference->getId()
        )->execute();

        $this->attachFilesToMessage($message);

        //@todo move this to a workflow activity (Async)
        if ($this->lead !== null) {
            $this->replaceLink(
                $this->lead,
                $message,
                $this->actionSlug,
                $this->engagementData->data
            );
        }

        return $message;
    }

    /**
     * Attach files to the message from DTO files or filesUrl.
     */
    protected function attachFilesToMessage(Message $message): void
    {
        // Attach Filesystem objects directly using HasFilesystemTrait
        foreach ($this->engagementData->files as $file) {
            if ($file instanceof Filesystem) {
                $message->addFile($file, $file->name);
            }
        }

        // Attach files from URLs using HasFilesystemTrait
        foreach ($this->engagementData->filesUrl as $fileUrl) {
            try {
                $message->addFileFromUrl($fileUrl, basename($fileUrl), $this->app);
            } catch (Exception $e) {
                report($e);
            }
        }
    }

    protected function createOrGetChannel(): ModelsChannel
    {
        if ($this->leadHooks !== null) {
            return $this->leadHooks->channel();
        }

        $slug = Str::sanitizeChannelName(
            EngagementReferenceMap::aliasFor($this->reference) . '-' . $this->reference->getEngagementChannelSlug()
        );

        return new CreateChannelAction(new Channel(
            apps: $this->app,
            companies: $this->company,
            users: $this->user,
            entity_id: $this->reference->getId(),
            entity_namespace: $this->reference::class,
            name: $slug,
            slug: $slug,
            description: $slug,
        ))->execute();
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
            ->where(fn (Builder $query) => $this->whereSameReference($query))
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
            ->where(fn (Builder $query) => $this->whereSameReference($query))
            ->where('people_id', $this->people->getId())
            ->where('companies_actions_id', $this->companyActionParent->getId())
            ->where('pipelines_stages_id', $submittedStage->getId())
            ->where('slug', $this->actionSlug)
            ->where('created_at', '>', $lastSentEngagement->created_at)
            ->exists();

        // If there's no submitted engagement after the last sent one, return it
        return $hasSubmittedEngagement ? null : $lastSentEngagement;
    }

    private function whereSameReference(Builder $query): Builder
    {
        return $this->lead !== null
            ? $query->where('leads_id', $this->lead->getId())
            : $query->whereReference($this->reference);
    }

    protected function createEngagement(Message $message): Engagement
    {
        $pipeline = Pipeline::getBySlug($this->actionSlug, $this->app, $this->company);
        $stage = $pipeline->stages()->where('slug', $this->engagementData->status->value)->firstOrFail();

        return Engagement::firstOrCreate([
            'companies_id' => $this->company->getId(),
            'apps_id' => $this->app->getId(),
            'users_id' => $this->user->getId(),
            'leads_id' => $this->lead?->getId(),
            'people_id' => $this->people->getId(),
            'entity_type' => EngagementReferenceMap::aliasFor($this->reference),
            'entity_id' => $this->reference->getId(),
            'companies_actions_id' => $this->companyActionParent->getId(),
            'message_id' => $message->getId(),
            'slug' => $this->actionSlug,
            'entity_uuid' => $this->engagementData->requestId,
            'pipelines_stages_id' => $stage->getId(),
            'parent_id' => $this->engagementData->parentEngagement?->getId(),
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
            'messageVideos' => ActionEnum::MESSAGE_VIDEO->value,
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
                'business-credit-app-1' => 'all-cash',
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
            $stripeCheckout = $this->stripePaymentLinkService($lead);

            $vehicleOfInterest = (array) $lead->get('vehicle_of_interest');
            $stockNumber = trim((string) ($vehicleOfInterest['stockNumber'] ?? '')) ?: 'N/A';
            $salesPerson = trim(($lead->owner?->firstname ?? '') . ' ' . ($lead->owner?->lastname ?? ''));
            $customerName = (string) ($lead->people?->name ?? '');
            $messageData = $message->message ?? [];

            $stripePayment = [
                'product_name' => 'Vehicle Purchase',
                'product_description' => 'Stock No: ' . $stockNumber,
                'success_url' => $messageData['action_link'] ?? '',
                'metadata' => [
                    'leads_id' => $lead->getId(),
                    'apps_id' => $lead->app->getId(),
                    'message_id' => $message->getId(),
                ],
                'custom_fields' => [
                    [
                        'key' => 'customer_name',
                        'label' => ['type' => 'custom', 'custom' => 'Customer Name'],
                        'type' => 'text',
                        'optional' => false, // Makes it required
                        'text' => [
                            'default_value' => $customerName,
                            'maximum_length' => 200,
                        ],
                    ],
                    [
                        'key' => 'stock_no',
                        'label' => ['type' => 'custom', 'custom' => 'Stock No'],
                        'type' => 'text',
                        'optional' => true,
                        'text' => [
                            'default_value' => $stockNumber,
                            'maximum_length' => 50,
                        ],
                    ],
                    [
                        'key' => 'sales_person',
                        'label' => ['type' => 'custom', 'custom' => 'Sales Person'],
                        'type' => 'text',
                        'optional' => true,
                        'text' => [
                            'default_value' => $salesPerson,
                            'maximum_length' => 50,
                        ],
                    ],
                ],
            ];

            $paymentLink = $stripeCheckout->generatePaymentLinkFromLeadMessage($lead, $message, $stripePayment);

            $paymentLinkFullLink = $paymentLink->url;
            if ($lead->people_id && $lead->people && $email = $lead->people->getEmails()->first()?->value) {
                $paymentLinkFullLink .= '?prefilled_email=' . urlencode($email);
            }
            $paymentShortLink = Url::getShortUrl($paymentLinkFullLink, $lead->app, company: $lead->company);

            $messageData['data']['link'] = $paymentShortLink;

            //@todo brya0x remove the action_link/preview_link override once clients read data.link
            $messageData['normal_action_link'] = $messageData['action_link'] ?? '';
            $messageData['action_link'] = $paymentShortLink;
            $messageData['preview_link'] = $paymentShortLink;
            $message->message = $messageData;
            $message->saveOrFail();
        }
    }

    protected function stripePaymentLinkService(Lead $lead): StripePaymentLinkService
    {
        return new StripePaymentLinkService($lead->app, $lead->company);
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
