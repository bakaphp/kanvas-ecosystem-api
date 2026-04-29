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
use Kanvas\Connectors\Movipass\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersInvite;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;
use Throwable;

/**
 * Auto-approve a corporate signup lead.
 *
 * Hook: WorkflowEnum::AFTER_RUNNING_RECEIVER on the Lead, fired by
 * CreateLeadsFromReceiverJob after a Lead is created from any Receiver Webhook.
 *
 * This activity self-filters: it only acts when the lead's receiver matches the
 * app-configured corporate receiver (movipass_corporate_receiver_id).
 *
 * Outcome:
 *   valid lead   -> Company created, is_corporate=true set, UsersInvite issued
 *                   under the app owner (inviter), welcome email sent with the
 *                   invite-acceptance link. User clicks the link, sets their
 *                   password, and the existing ProcessInviteAction creates the
 *                   User + assigns the Admins role to the new corporate Company.
 *   invalid lead -> lead marked needs_review, needs-review email sent to user
 *
 * Idempotency: on retry, the activity reuses the Company / UsersInvite stored
 * on the lead's custom fields (`movipass_corporate_company_id`,
 * `movipass_corporate_invite_hash`) instead of creating duplicates.
 *
 * Toggle: movipass_corporate_auto_approve app config (default true). When false,
 * every corporate lead lands in needs_review regardless of validation result.
 */
class AutoApproveCorporateLeadActivity extends KanvasActivity implements WorkflowActivityInterface
{
    /**
     * Workflow-internal bookkeeping keys stored on the Lead. Kept namespaced
     * because the Lead can be touched by multiple workflows from different
     * connectors and we want collision-free markers.
     */
    private const STATUS_FIELD = 'movipass_corporate_status';
    private const STATUS_REASON_FIELD = 'movipass_corporate_status_reason';
    private const COMPANY_ID_FIELD = 'movipass_corporate_company_id';
    private const INVITE_HASH_FIELD = 'movipass_corporate_invite_hash';

    /**
     * Corporate identity fields that the FE wizard sends on the Lead and the
     * workflow copies to the new Company. Names are intentionally bare —
     * `is_corporate=true` on the Company already establishes the corporate
     * context, so we don't repeat the namespace on every field.
     */
    private const CORPORATE_COMPANY_FIELDS = [
        'legal_name',
        'commercial_name',
        'rnc',
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

    /**
     * Validate the lead's payload against business rules.
     *
     * Returns null on success or a short reason string on failure (used as
     * the lead's needs_review reason and rendered in the user-facing email).
     */
    private function validate(Lead $lead, AppInterface $app): ?string
    {
        $rnc = trim((string) $lead->get('rnc'));

        if ($rnc === '') {
            return 'RNC is required';
        }

        // DR RNC: 9 digits (companies) or 11 digits (individuals/some companies),
        // accept arbitrary separators (e.g., "131-12345-6") and validate digit count.
        $rncDigits = preg_replace('/\D/', '', $rnc) ?? '';

        if (! in_array(strlen($rncDigits), [9, 11], true)) {
            return 'RNC must be 9 or 11 digits';
        }

        $legalName = trim((string) $lead->get('legal_name'));

        if ($legalName === '') {
            return 'Legal name is required';
        }

        $email = trim((string) $lead->email);

        if ($email === '') {
            return 'Email is required';
        }

        // Email uniqueness is intentionally NOT enforced here. A user can have
        // both a personal account and a corporate account on the same app —
        // they're different Companies. If the email already exists,
        // ProcessInviteAction will link the new corporate Company to the
        // existing User when the invite is accepted.

        // TODO (Phase 2 follow-up): rate-limit per IP if available
        // TODO (Phase 2 follow-up): blocked-domain list (movipass_corporate_blocked_domains app config)

        return null;
    }

    /**
     * Create the corporate Company, copy the lead's corporate custom fields,
     * issue a UsersInvite for the contact email, and send the welcome email
     * with the invite-acceptance link.
     *
     * Idempotent: on retry, reuses Company/Invite stored on the lead.
     */
    private function approve(Lead $lead, AppInterface $app): array
    {
        try {
            $appOwner = $this->resolveAppOwner($app);
            $company = $this->findOrCreateCompany($lead, $appOwner);

            $this->copyCorporateCustomFields($lead, $company);

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
            // Re-throw so KanvasActivity's retry logic kicks in (3 tries built in).
            report($e);

            throw $e;
        }
    }

    /**
     * Mark the lead for manual review and notify the user.
     */
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

    /**
     * Lead doesn't match this activity's scope. Return early without touching state.
     */
    private function skip(Lead $lead, string $reason): array
    {
        return [
            'lead' => $lead->getId(),
            'status' => 'skipped',
            'reason' => $reason,
        ];
    }

    /**
     * Resolve the app owner — used as the inviter on the UsersInvite record
     * and as the user attached to the new Company. The auto-approval workflow
     * has no authenticated user, so the system "speaks for" the app owner.
     */
    private function resolveAppOwner(AppInterface $app): Users
    {
        $appsModel = $app instanceof Apps ? $app : app(Apps::class);

        return Users::getById((int) $appsModel->users_id);
    }

    /**
     * Find or create the corporate Company. Idempotent across retries.
     */
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

    /**
     * Set is_corporate=true and copy the corporate identity fields from the
     * lead to the new Company. Allowlisted explicitly so leads carrying
     * unrelated keys (from other workflows touching the lead) don't bleed
     * into the Company.
     */
    private function copyCorporateCustomFields(Lead $lead, Companies $company): void
    {
        $company->set('is_corporate', true);

        foreach (self::CORPORATE_COMPANY_FIELDS as $key) {
            $value = $lead->get($key);

            if ($value === null || $value === '') {
                continue;
            }

            $company->set($key, $value);
        }
    }

    /**
     * Find or create the UsersInvite that the contact will use to claim their
     * account and set their password. ProcessInviteAction (existing) handles
     * User creation + Admin role assignment on the corporate Company when the
     * user submits the invite-acceptance form.
     *
     * Idempotent across retries via lead.movipass_corporate_invite_hash.
     */
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

        return $invite;
    }

    /**
     * Send the corporate welcome email with the invite-acceptance link.
     */
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

    /**
     * Send the needs-review email so the user knows their application is being
     * checked manually instead of going silent.
     */
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

    /**
     * Wrap the Blank notification + Notification::route('mail', ...) pattern.
     * Mirrors the public `anonymousNotification` mutation in
     * NotificationsManagementMutation.
     */
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
            // Don't fail the activity if email delivery hiccups — log and continue.
            // The Company/Invite were created successfully; the user can be reached
            // out to manually if the email never arrives.
            report($e);
        }
    }
}
