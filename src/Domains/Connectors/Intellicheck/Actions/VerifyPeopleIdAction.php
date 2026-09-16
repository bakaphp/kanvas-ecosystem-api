<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intellicheck\Actions;

use Baka\Support\Str;
use Illuminate\Database\Eloquent\ModelNotFoundException as EloquentModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Kanvas\ActionEngine\Engagements\Actions\CreateEngagementAction;
use Kanvas\ActionEngine\Engagements\DataTransferObject\Engagement as DataTransferObjectEngagement;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Engagements\Repositories\EngagementRepository;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Connectors\Intellicheck\Services\IdVerificationService;
use Kanvas\Connectors\SalesAssist\Enums\ConfigurationEnum;
use Kanvas\Connectors\SalesAssist\Services\DriverLicenseCombinedPdfService;
use Kanvas\Connectors\SalesAssist\Services\DriverLicenseVerificationService;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Filesystem\Services\PdfService;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;
use Throwable;

class VerifyPeopleIdAction
{
    public function __construct(
        protected People $people,
        protected Lead $lead
    ) {
    }

    public function execute(
        array $verificationData,
        bool $sendNotification = true,
        ?Engagement $parentEngagement = null,
        ?array $images = null,
        bool $reuseExistingEngagement = false
    ): array {
        // An in-store scan carries no IPQS block; the fraud rules only apply to remote ones.
        $isShowRoom = ! isset($verificationData['ipqs']);
        $name = IdVerificationService::getName($verificationData);
        $name = $name !== 'Unknown' ? $name : ($this->lead->title ?? ($this->people->name ?? 'Customer'));
        $app = $this->lead->app;

        $verificationResults = IdVerificationService::processVerificationData($verificationData, $name, $isShowRoom);

        $reportData = [
                       'name' => $name,
                       'status' => $verificationResults['status'],
                       'message' => $verificationResults['message'],
                       'flags' => $verificationResults['flags'],
                       'failures' => $verificationResults['failures'],
                       'results' => $verificationResults['results'],
                       'verificationData' => $verificationData,
                       'id_verification_status' => $verificationResults['status'],
                       'id_verification_message' => $verificationResults['message'],
                       'id_verification_result' => [
                           'intelicheck' => $verificationResults['status'] == 'green' || $verificationResults['status'] == 'flag',
                           'status' => $verificationResults['status'],
                           'message' => $verificationResults['message'],
                           'scandit' => $verificationResults['status'] == 'green' || $verificationResults['status'] == 'flag',
                           'expired' => $verificationResults['status'] == 'flag',
                           'ocMatch' => $verificationResults['ocMatch'] ?? false,
                           'intellicheckResponse' => $verificationResults['status'],
                       ],
                   ];

        $getDocsDriversLicense = IdVerificationService::toDriverLicenseScan($verificationData);

        $resultsFromIntellicheck = [
            'intelicheck' => $verificationResults['status'] == 'green' || $verificationResults['status'] == 'flag' ? true : false,
            'status' => $verificationResults['status'],
            'message' => $verificationResults['message'],
            'scandit' => $verificationResults['status'] == 'green' || $verificationResults['status'] == 'flag' ? true : false,
            'expired' => $verificationResults['status'] == 'flag' ? true : false,
            'ocMatch' => $verificationResults['ocMatch'] ?? false,
            'intellicheck_workflow_response' => $reportData['status'] === 'green' ? 'passed' : $reportData['status'],
            'intellicheckResponse' => $reportData['status'] === 'green' ? 'passed' : $reportData['status'],
        ];

        $lead = $this->lead;
        $people = $this->people;
        $isLeadPeople = $lead->people_id === $people->id;

        $people->set('id_verification', $resultsFromIntellicheck);

        // The lead's own slots only ever describe the main buyer, never a co-buyer.
        if ($isLeadPeople) {
            $lead->set('id_verification', $resultsFromIntellicheck);
        }

        // The lead field is only a showroom hand-off; persist so direct pushes see it too.
        if (! empty($getDocsDriversLicense)) {
            new DriverLicenseVerificationService(
                $app,
                $people->company,
                $lead->user,
            )->updatePeopleFromDriverLicense($people, $getDocsDriversLicense);
        }

        if (! empty($getDocsDriversLicense) && $isLeadPeople) {
            $lead->set('get_docs_drivers_license', $getDocsDriversLicense);
        }
        $people->del('get_docs_drivers_license');

        $engagement = null;

        if ($sendNotification) {
            $engagement = $this->sendNotification(
                reportData: $reportData,
                isShowRoom: $isShowRoom,
                parentEngagement: $parentEngagement,
                images: $images,
                reuseExistingEngagement: $reuseExistingEngagement
            );
        }

        return [
            'report' => $reportData['status'] === 'green' ? 'passed' : $reportData['status'],
            'result' => true,
            'message' => 'IdVerificationReportActivity executed successfully',
            'data' => $reportData,
            'resultsFromIntellicheck' => $resultsFromIntellicheck,
            'getDocsDriversLicense' => $getDocsDriversLicense ?? null,
            'engagement_id' => $engagement?->getId(),
        ];
    }

    /**
     * A short TTL rather than a persisted flag: a queue retry must not send a second report, but a
     * re-scan after a failed check must. Keyed by person id, not display name — an unreadable document
     * resolves the name back to the main buyer's, and the two would skip each other.
     */
    protected function sendNotification(
        array $reportData,
        bool $isShowRoom,
        ?Engagement $parentEngagement,
        ?array $images,
        bool $reuseExistingEngagement
    ): ?Engagement {
        $cacheKey = 'intellicheck_report_' . $this->lead->getId() . '_' . $this->people->getId();

        if (Cache::has($cacheKey)) {
            return null;
        }

        Cache::put($cacheKey, true, now()->addMinutes(3));

        if (! $this->lead->company->get('disable_id_verification_email', false)) {
            $notification = new Blank(
                'id-verification-report',
                $this->templateData($reportData, $isShowRoom),
                ['mail'],
                $this->lead,
            );
            $notification->setSubject($reportData['name'] . ' - ID Verification Report');

            Notification::send($this->reportRecipients(), $notification);
        }

        return $this->generateReportPdf(
            $reportData,
            $isShowRoom,
            $parentEngagement,
            $images,
            $reuseExistingEngagement
        );
    }

    protected function generateReportPdf(
        array $reportData,
        bool $isShowRoom,
        ?Engagement $parentEngagement,
        ?array $images,
        bool $reuseExistingEngagement
    ): ?Engagement {
        try {
            $pdfReport = PdfService::generatePdfFromTemplate(
                $this->lead->app,
                $this->people->user,
                'id-verification-report',
                $this->people,
                $this->templateData($reportData, $isShowRoom)
            );

            $engagement = $this->resolveEngagement($parentEngagement, $reuseExistingEngagement);

            if ($engagement === null) {
                return null;
            }

            $this->processDriverLicenseImages(
                engagement: $engagement,
                imageFields: $this->resolveImageFields($images, $parentEngagement, $reuseExistingEngagement),
                isIdValid: in_array($reportData['status'], ['green', 'flag']),
                verificationResults: $reportData,
                isExpired: $reportData['status'] === 'flag'
            );
            $engagement->message->addFile($pdfReport, 'id-verification');

            return $engagement;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    protected function templateData(array $reportData, bool $isShowRoom): array
    {
        return [
            'message' => $reportData['message'],
            'status' => $reportData['status'],
            'flags' => $reportData['flags'],
            'failures' => $reportData['failures'],
            'results' => $reportData['results'],
            'isShowRoom' => $isShowRoom,
            'verificationData' => $reportData['verificationData'],
        ];
    }

    protected function reportRecipients(): Collection
    {
        $company = $this->lead->company;

        $recipients = UsersRepository::findUsersByArray((array) $company->get('company_manager'), $this->lead->app);

        // A company that never created the Manager role has no managers; that must not sink the report.
        try {
            $recipients = $recipients->merge(
                UsersRepository::getCompanyAppUserByRole($company, $this->lead->app, 'Manager')->get()
            );
        } catch (EloquentModelNotFoundException) {
        }

        return $this->lead->owner !== null ? $recipients->merge([$this->lead->owner]) : $recipients;
    }

    /**
     * A report that creates its own root message renders as a second folder, so it threads under the
     * scan's engagement or reuses this person's. Reuse is opt-in because it moves where the legacy
     * callers' files land — see `Connectors/Intellicheck/CLAUDE.md`.
     */
    public function resolveEngagement(?Engagement $parentEngagement = null, bool $reuseExistingEngagement = false): ?Engagement
    {
        if ($parentEngagement !== null) {
            return $this->createEngagement($parentEngagement);
        }

        if (! $reuseExistingEngagement) {
            return $this->createEngagement();
        }

        $existing = EngagementRepository::findEngagementForLeadPeople(
            $this->lead,
            $this->people,
            ConfigurationEnum::ID_VERIFICATION->value,
            ActionStatusEnum::SUBMITTED->value
        );

        // Every caller dereferences ->message, so an engagement without one is no use.
        return $existing?->message !== null ? $existing : $this->createEngagement();
    }

    protected function createEngagement(?Engagement $parentEngagement = null): ?Engagement
    {
        $user = $this->resolveEngagementUser();

        if ($user === null) {
            return null;
        }

        $taskId = $this->lead->get('check_list_status') ?? $this->lead->company->get('default_checklist_id');

        if (is_array($taskId)) {
            $taskId = $taskId['activeTaskListId'] ?? $this->lead->company->get('default_checklist_id');
        }

        $engagementData = new DataTransferObjectEngagement(
            app: $this->lead->app,
            company: $this->lead->company,
            user: $user,
            lead: $this->lead,
            action: ConfigurationEnum::ID_VERIFICATION->value,
            // Reusing the parent's entity_uuid keeps `stageHistory()` grouping the two rows together.
            requestId: $parentEngagement->entity_uuid ?? Str::uuid()->toString(),
            source: 'workflow',
            status: ActionStatusEnum::SUBMITTED,
            people: $this->people,
            receiverId: $this->lead->receiver?->getId(),
            taskId: $taskId,
            via: 'webhook',
            data: [],
            parentEngagement: $parentEngagement,
        );

        return new CreateEngagementAction($engagementData)->execute();
    }

    /**
     * Unassigned leads carry `leads_owner_id = 0`, and a stale `users_id` outside the app makes
     * `CreateEngagementAction` throw while following the lead — so each candidate is checked for
     * membership before it owns the engagement.
     */
    protected function resolveEngagementUser(): ?Users
    {
        foreach ([$this->lead->owner, $this->lead->user, $this->people->user] as $candidate) {
            if ($candidate === null) {
                continue;
            }

            try {
                UsersRepository::belongsToThisApp(
                    $candidate,
                    $this->lead->app,
                    $this->lead->company
                );

                return $candidate;
            } catch (ModelNotFoundException) {
                continue;
            }
        }

        return null;
    }

    /**
     * Each side is base64 or a filesystem uuid. The parent's file is re-linked because a folder renders
     * only its last submitted child's files, so a side left on the parent vanishes from the UI.
     *
     * @return array<string, ?string>
     */
    protected function resolveImageFields(
        ?array $images,
        ?Engagement $parentEngagement,
        bool $reuseExistingEngagement
    ): array {
        $fallback = $reuseExistingEngagement ? [] : $this->customFieldImages();
        $resolved = ['face_image' => $images['face'] ?? null];

        foreach (['front' => 'drivers_license_front', 'back' => 'drivers_license_back'] as $side => $fieldName) {
            $resolved[$fieldName] = $images[$side]
                ?? $this->parentMessageImage($parentEngagement, $fieldName)
                ?? $fallback[$side]
                ?? null;
        }

        return $resolved;
    }

    /**
     * @deprecated `people.driver_license_images` is a one-shot base64 mailbox written late by an external
     *             caller. Drop with `after-id-verification`; read without `del()` because that verb still
     *             waits on it.
     *
     * @return array<string, ?string>
     */
    protected function customFieldImages(): array
    {
        $images = $this->people->get('driver_license_images');

        return is_array($images) ? $images : [];
    }

    protected function parentMessageImage(?Engagement $parentEngagement, string $fieldName): ?string
    {
        return $parentEngagement?->message?->getFileByName($fieldName)?->filesystem?->uuid;
    }

    protected function processDriverLicenseImages(
        Engagement $engagement,
        array $imageFields,
        bool $isIdValid,
        array $verificationResults,
        bool $isExpired = false
    ): void {
        foreach ($imageFields as $fieldName => $image) {
            // `addFile` repoints an existing field_name, which would overwrite the customer's own upload
            // with a re-uploaded copy of the same document.
            if ($image === null || $engagement->message->getFileByName($fieldName) !== null) {
                continue;
            }

            $file = $this->resolveFile($image, $fieldName . '.jpg');

            if ($file === null) {
                continue;
            }

            $engagement->message->addFile($file, $fieldName);

            $file->set('id_verify', (int) $isIdValid);
            $file->set('id_expired', (int) $isExpired);
            $file->set('id_verification_msg', $verificationResults['message']);
            $file->set('id_verification_status', $verificationResults['status'] ?? 'unknown');
        }

        new DriverLicenseCombinedPdfService($engagement->message)->attach(
            $isIdValid,
            $isExpired,
            (string) ($verificationResults['message'] ?? ''),
            (string) ($verificationResults['status'] ?? 'unknown'),
        );
    }

    /**
     * Tenant-scoped because the uuid comes from a caller: an unscoped lookup would attach another
     * company's file.
     */
    protected function resolveFile(string $image, string $fileName): ?Filesystem
    {
        if (! Str::isUuid($image)) {
            return $this->createFileFromBase64($image, $fileName);
        }

        try {
            return Filesystem::getByUuidFromCompanyApp($image, $this->lead->company, $this->lead->app);
        } catch (Throwable) {
            return null;
        }
    }

    protected function createFileFromBase64(
        string $base64Data,
        string $fileName = 'driver_license_image.jpg'
    ): Filesystem {
        $filesystemService = new FilesystemServices($this->lead->app, $this->lead->company);

        return $filesystemService->createFileSystemFromBase64(
            $base64Data,
            $fileName,
            $this->lead->user
        );
    }
}
