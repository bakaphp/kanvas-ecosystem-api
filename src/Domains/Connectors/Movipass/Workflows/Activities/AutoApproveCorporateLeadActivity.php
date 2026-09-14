<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification as LaravelNotification;
use Illuminate\Support\Str;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Actions\CreateCompaniesAction;
use Kanvas\Companies\DataTransferObject\Company as CompanyData;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Movipass\Actions\ValidateCorporateFieldsAction;
use Kanvas\Connectors\Movipass\Enums\ConfigurationEnum;
use Kanvas\Connectors\Movipass\Enums\CustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Inventory\Regions\Enums\CustomFieldEnum as RegionCustomFieldEnum;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersInvite;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;
use Throwable;

#[WorkflowAction]
class AutoApproveCorporateLeadActivity extends KanvasActivity implements WorkflowActivityInterface
{
    // Lead bookkeeping fields are namespaced to avoid collision with other connector workflows.
    private const STATUS_FIELD = 'movipass_corporate_status';
    private const STATUS_REASON_FIELD = 'movipass_corporate_status_reason';
    private const COMPANY_ID_FIELD = 'movipass_corporate_company_id';
    private const INVITE_HASH_FIELD = 'movipass_corporate_invite_hash';

    public const CORPORATE_COMPANY_FIELDS = [
        'legal_name',
        'commercial_name',
        'rnc',
    ];

    public const CORPORATE_USER_FIELDS = [
        'is_corporate',
        'contact_name',
        'contact_role',
        'contact_email',
        'contact_phone',
    ];

    #[Override]
    public function execute(Model $lead, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $lead,
            app: $app,
            integration: IntegrationsEnum::MOVIPASS,
            additionalParams: $params,
            integrationOperation: function ($lead, $app, $integrationCompany, $additionalParams) {
                /** @var Lead $lead */
                $configuredReceiverId = $app->get(ConfigurationEnum::CORPORATE_RECEIVER_ID->value);

                if (empty($configuredReceiverId)) {
                    return $this->skip($lead, 'corporate receiver not configured for this app');
                }

                if ((int) $lead->leads_receivers_id !== (int) $configuredReceiverId) {
                    return $this->skip($lead, 'lead is not from the corporate receiver');
                }

                $autoApproveEnabled = (bool) ($app->get(ConfigurationEnum::CORPORATE_AUTO_APPROVE->value) ?? true);

                if (! $autoApproveEnabled) {
                    return $this->markNeedsReview($lead, $app, 'auto-approve disabled by app config');
                }

                $validationError = $this->validate($lead, $app);

                if ($validationError !== null) {
                    return $this->markNeedsReview($lead, $app, $validationError);
                }

                return $this->approve($lead, $app);
            },
            company: $lead->company,
        );
    }

    private function validate(Lead $lead, AppInterface $app): ?string
    {
        return new ValidateCorporateFieldsAction([
            'rnc' => $lead->get('rnc'),
            'legal_name' => $lead->get('legal_name'),
            'contact_email' => $lead->get('contact_email') ?: $lead->email,
        ])->execute();
    }

    private function approve(Lead $lead, AppInterface $app): array
    {
        try {
            $appOwner = $lead->receiver->user;
            $company = $this->findOrCreateCompany($lead, $appOwner);

            $this->copyCompanyFieldsFromLead($lead, $company);

            $invite = $this->findOrCreateInvite($lead, $company, $app, $appOwner);

            $lead->set(self::COMPANY_ID_FIELD, (string) $company->getId());
            $lead->set(self::INVITE_HASH_FIELD, $invite->invite_hash);
            $lead->set(self::STATUS_FIELD, 'approved');

            $this->sendWelcomeEmail($app, $lead, $company, $invite);

            return [
                'lead' => $lead->getId(),
                'status' => 'approved',
                'company_id' => $company->getId(),
                'invite_hash' => $invite->invite_hash,
            ];
        } catch (Throwable $e) {
            report($e);

            throw $e;
        }
    }

    private function markNeedsReview(Lead $lead, AppInterface $app, string $reason): array
    {
        $lead->set(self::STATUS_FIELD, 'needs_review');
        $lead->set(self::STATUS_REASON_FIELD, $reason);

        $this->sendNeedsReviewEmail($app, $lead, $reason);

        return [
            'lead' => $lead->getId(),
            'status' => 'needs_review',
            'reason' => $reason,
        ];
    }

    private function skip(Lead $lead, string $reason): array
    {
        return [
            'lead' => $lead->getId(),
            'status' => 'skipped',
            'reason' => $reason,
        ];
    }

    private function findOrCreateCompany(Lead $lead, Users $appOwner): Companies
    {
        $existingCompanyId = $lead->get(self::COMPANY_ID_FIELD);

        if ($existingCompanyId) {
            return Companies::getById((int) $existingCompanyId);
        }

        $companyName = trim((string) ($lead->get('commercial_name')
            ?: $lead->get('legal_name')
            ?: $lead->title
            ?: 'Corporate Account'));

        return new CreateCompaniesAction(
            new CompanyData(
                user: $appOwner,
                name: $companyName,
                email: trim((string) ($lead->get('contact_email') ?: $lead->email)),
                phone: trim((string) ($lead->get('contact_phone') ?: $lead->phone ?? '')),
            ),
        )->execute();
    }

    private function copyCompanyFieldsFromLead(Lead $lead, Companies $company): void
    {
        $company->set('is_corporate', true);

        foreach (self::CORPORATE_COMPANY_FIELDS as $key) {
            $value = $lead->get($key);

            if ($value === null || $value === '') {
                continue;
            }

            $company->set($key, $value);
        }

        $regionId = $lead->get('region_id');
        if (! empty($regionId)) {
            $this->setCompanyRegion($company, (int) $regionId);
        } elseif (app()->bound(Regions::class)) {
            $this->setCompanyRegion($company, app(Regions::class)->getId());
        }
    }

    // movipass_region_id predates the generic key; keep writing both until the legacy
    // readers are gone, so RegionResolutionService::forCompany() sees corporate companies.
    private function setCompanyRegion(Companies $company, int $regionId): void
    {
        $company->set(CustomFieldEnum::COMPANY_REGION_ID->value, $regionId);
        $company->set(RegionCustomFieldEnum::DEFAULT_REGION_ID->value, $regionId);
    }

    private function findOrCreateInvite(Lead $lead, Companies $company, AppInterface $app, Users $appOwner): UsersInvite
    {
        $existingHash = $lead->get(self::INVITE_HASH_FIELD);

        if ($existingHash) {
            $existing = UsersInvite::where('invite_hash', $existingHash)->first();

            if ($existing) {
                return $existing;
            }
        }

        $appsModel = $app instanceof Apps ? $app : app(Apps::class);
        $branch = $company->branch()->firstOrFail();
        $adminRole = RolesRepository::getByMixedParamFromCompany(RolesEnums::ADMIN->value, $company, $appsModel);

        $invite = new UsersInvite();
        $invite->fill([
            'invite_hash' => Str::random(50),
            'users_id' => $appOwner->getId(),
            'companies_id' => $company->getId(),
            'companies_branches_id' => $branch->getId(),
            'role_id' => $adminRole->id,
            'apps_id' => $appsModel->getId(),
            'email' => trim((string) ($lead->get('contact_email') ?: $lead->email)),
            'firstname' => trim((string) ($lead->get('contact_name') ?: $lead->firstname ?: '')),
            'lastname' => trim((string) ($lead->lastname ?: '')),
            'description' => 'Corporate self-signup',
        ]);
        $invite->saveOrFail();

        // is_corporate marker is what PropagateCorporateFieldsToUserActivity looks for.
        $invite->set('is_corporate', true);
        foreach (self::CORPORATE_USER_FIELDS as $key) {
            if ($key === 'is_corporate') {
                continue;
            }
            $value = $lead->get($key);
            if ($value === null || $value === '') {
                continue;
            }
            $invite->set($key, $value);
        }

        return $invite;
    }

    private function sendWelcomeEmail(AppInterface $app, Lead $lead, Companies $company, UsersInvite $invite): void
    {
        $templateName = $app->get(ConfigurationEnum::CORPORATE_WELCOME_TEMPLATE->value)
            ?: 'corporate-welcome';

        $inviteBaseUrl = rtrim((string) ($app->get(ConfigurationEnum::CORPORATE_INVITE_LINK_BASE->value) ?? ''), '/');
        $inviteUrl = $inviteBaseUrl !== ''
            ? $inviteBaseUrl . '/' . $invite->invite_hash
            : null;

        $this->sendAnonymousEmail(
            $app,
            $lead,
            (string) $templateName,
            'Bienvenido al portal corporativo',
            [
                'lead' => $lead,
                'company' => $company,
                'invite' => $invite,
                'inviteHash' => $invite->invite_hash,
                'inviteUrl' => $inviteUrl,
                'corporateLegalName' => $lead->get('legal_name'),
                'contactName' => $lead->get('contact_name') ?? $lead->firstname,
            ],
        );
    }

    private function sendNeedsReviewEmail(AppInterface $app, Lead $lead, string $reason): void
    {
        $templateName = $app->get(ConfigurationEnum::CORPORATE_NEEDS_REVIEW_TEMPLATE->value)
            ?: 'corporate-needs-review';

        $this->sendAnonymousEmail(
            $app,
            $lead,
            (string) $templateName,
            'Tu solicitud está en revisión',
            [
                'lead' => $lead,
                'reason' => $reason,
                'contactName' => $lead->get('contact_name') ?? $lead->firstname,
            ],
        );
    }

    private function sendAnonymousEmail(AppInterface $app, Lead $lead, string $templateName, string $subject, array $data): void
    {
        $email = trim((string) $lead->email);

        if ($email === '') {
            return;
        }

        $data['app'] = $app;

        $notification = new Blank($templateName, $data, ['mail'], $lead);
        $notification->setSubject($subject);

        try {
            LaravelNotification::route('mail', $email)->notify($notification);
        } catch (Throwable $e) {
            // Email failures don't fail the activity — Company/Invite are the source of truth.
            report($e);
        }
    }
}
