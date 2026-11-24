<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Engagements\Support;

use Baka\Support\Str;
use Baka\Support\Url;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Engagements\DataTransferObject\Engagement as EngagementData;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel;
use Kanvas\Social\Channels\Models\Channel as ChannelModel;
use Kanvas\Users\Models\Users;

/**
 * Everything CreateEngagementAction does only because the reference is a lead: following it, its
 * receiver and branch, the legacy landing-page link built from lead params, the per-lead link memo
 * and the lead channel. An engagement on any other reference skips all of it.
 */
class LeadEngagementHooks
{
    private const string PREVIOUS_LINK_KEY = 'engagement_previous_link';

    public function __construct(
        protected readonly Lead $lead,
        protected readonly EngagementData $engagementData,
    ) {
    }

    public function follow(Users $user): void
    {
        $user->follow($this->lead);
    }

    public function receiver(): LeadReceiver
    {
        $app = $this->engagementData->app;
        $company = $this->engagementData->company;

        return $this->engagementData->receiverId !== null
            ? LeadReceiver::getByIdFromCompanyApp($this->engagementData->receiverId, $company, $app)
            : ($this->lead->receiver ?? LeadReceiver::getDefault($company, $app));
    }

    public function branch(): ?CompaniesBranches
    {
        return $this->lead->branch;
    }

    /**
     * With duplicates disallowed, the link generated for an action the first time is reused for the
     * lead's next engagement on the same action.
     */
    public function rememberedLinkMessageData(callable $generate): array
    {
        $previousLinks = $this->lead->get(self::PREVIOUS_LINK_KEY) ?? [];
        $hasPreviousLinks = ! empty($previousLinks) && is_array($previousLinks);

        if ($hasPreviousLinks && array_key_exists($this->engagementData->action, $previousLinks)) {
            return $previousLinks[$this->engagementData->action];
        }

        $messageData = $generate();
        $previousLinks = $hasPreviousLinks ? $previousLinks : [];
        $previousLinks[$this->engagementData->action] = $messageData;
        $this->lead->set(self::PREVIOUS_LINK_KEY, $previousLinks);

        return $messageData;
    }

    public function linkMessageData(
        LeadReceiver $receiver,
        People $people,
        string $actionSlug,
        CompanyAction $companyAction
    ): array {
        $app = $this->engagementData->app;
        $company = $this->engagementData->company;
        $user = $this->engagementData->user;

        $newActionPageUrl = in_array($actionSlug, $app->get('new-action-slug') ?? []);
        $actionPageUrl = ! $newActionPageUrl ? $app->get('TEMP_LANDING_PAGE') : $app->get('NEW_LANDING_PAGE');

        $params = [
            'visitors_id' => $this->engagementData->requestId,
            'visitor_id' => $this->engagementData->requestId,
            'users_id' => $user->getId(),
            'vehicle_id' => null,
            'leads_id' => $this->lead->uuid,
            'lead_id' => $this->lead->uuid,
            'receivers_id' => $receiver->uuid,
            'receiver_id' => $receiver->uuid,
            'contacts_id' => $people->uuid,
            'actions_slug' => $actionSlug,
            'cid' => $this->lead->company->uuid,
            'bcid' => $this->lead->branch ? $this->lead->branch->uuid : null,
            'form_type' => $this->engagementData->formType,
        ];

        if ($company->get('hide_millage')) {
            $params['welcome'] = 'false';
        }

        $extraField = $this->engagementData->extraField;
        if (is_array($extraField)) {
            $extraField = implode('&', $extraField);
        }

        $urlParams = http_build_query(array_filter($params)) . ($extraField ? '&' . $extraField : '');
        $urlParams .= '&caction=' . $companyAction->uuid;

        if ($actionSlug === 'get-deposit') {
            $urlParams .= '&ia=1';
        }

        $companyLanguage = $this->lead->company->get('COMPANY_MULTI_LANGUAGE');
        if ($companyLanguage) {
            $urlParams .= '&lang=' . $companyLanguage;
        }

        $url = $actionPageUrl . "/{$actionSlug}?{$urlParams}";
        $urlPreview = $actionPageUrl . "/{$actionSlug}?{$urlParams}&preview=true";

        $reasonEnglish = $companyAction->get('reasonEn');
        $reasonSpanish = $companyAction->get('reasonEs');

        $messageEnglish = 'Hi {name}, this is ' . $user->firstname . ' from ' . $this->lead->branch->name . '. Click the link below to ' . $reasonEnglish;
        $messageSpanish = 'Hola {name}, es ' . $user->firstname . ' de ' . $this->lead->branch->name . '. Haz click al siguiente enlace para ' . $reasonSpanish;

        return [
            'link' => Url::getShortUrl($url, $app, company: $this->lead->company),
            'link_preview' => Url::getShortUrl($urlPreview, $app, company: $this->lead->company),
            'link_full' => $url,
            'link_full_preview' => $urlPreview,
            'data' => $companyAction->form_config,
            'params' => array_merge($params, $this->engagementData->extraData),
            'preview_image' => null,
            'message_content' => [
                'ENG' => $reasonEnglish !== null && Str::endsWith($reasonEnglish, '!') ? $messageEnglish : $messageEnglish . '. ',
                'ES' => $reasonSpanish !== null && Str::endsWith($reasonSpanish, '!') ? $messageSpanish : $messageSpanish . '. ',
            ],
        ];
    }

    public function channel(): ChannelModel
    {
        $leadUuid = (string) $this->lead->uuid;

        return new CreateChannelAction(new Channel(
            apps: $this->engagementData->app,
            companies: $this->lead->company,
            users: $this->lead->user,
            entity_id: $this->lead->getId(),
            entity_namespace: Lead::class,
            name: $leadUuid,
            slug: $leadUuid,
            description: $leadUuid,
        ))->execute();
    }
}
