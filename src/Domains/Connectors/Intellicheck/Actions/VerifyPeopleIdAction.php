<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intellicheck\Actions;

use Baka\Support\Str;
use Illuminate\Support\Facades\Cache;
use Kanvas\ActionEngine\Engagements\Actions\CreateEngagementAction;
use Kanvas\ActionEngine\Engagements\DataTransferObject\Engagement as DataTransferObjectEngagement;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Engagements\Repositories\EngagementRepository;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Connectors\Intellicheck\Services\IdVerificationService;
use Kanvas\Connectors\SalesAssist\Enums\ConfigurationEnum;
use Kanvas\Connectors\SalesAssist\Services\DriverLicenseCombinedPdfService;
use Kanvas\Connectors\SalesAssist\Services\DriverLicenseVerificationService;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Filesystem\Services\PdfService;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Users\Repositories\UsersRepository;
use Throwable;

class VerifyPeopleIdAction
{
    protected ?array $customFieldImages = null;

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

        // Process data to generate verification results
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

        // Get lead and people from entity
        $lead = $this->lead;
        $people = $this->people;
        $isLeadPeople = false;

        if ($this->lead->people_id === $this->people->id) {
            $isLeadPeople = true;
            $lead->set(
                'id_verification',
                $resultsFromIntellicheck
            );

            $people->set(
                'id_verification',
                $resultsFromIntellicheck
            );
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

        if ($sendNotification) {
            $this->sendNotification(
                verificationData: $verificationData,
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
        ];
    }

    /**
     * Deduped per verified person on a short TTL, not with a persisted flag: a queue retry must not
     * send a second report, but a customer re-scanning after a failed check must still get one. The
     * key is the person's id rather than the display name — a participant whose document is
     * unreadable resolves `$name` back to the main buyer's, so a name-keyed guard would make the two
     * silently skip each other.
     */
    protected function sendNotification(
        array $verificationData,
        array $reportData,
        bool $isShowRoom,
        ?Engagement $parentEngagement,
        ?array $images,
        bool $reuseExistingEngagement
    ): void {
        $cacheKey = 'intellicheck_report_' . $this->lead->getId() . '_' . $this->people->getId();

        if (Cache::has($cacheKey)) {
            return;
        }

        Cache::put($cacheKey, true, now()->addMinutes(3));

        $managers = UsersRepository::getCompanyAppUserByRole($this->people->company, $this->people->app, 'Manager')->get();

        $notification = new Blank(
            'id-verification-report',
            [
                'message' => $reportData['message'],
                'status' => $reportData['status'],
                'flags' => $reportData['flags'],
                'failures' => $reportData['failures'],
                'results' => $reportData['results'],
                'isShowRoom' => $isShowRoom,
                'verificationData' => $verificationData,
            ],
            ['mail'],
            $this->lead,
        );

        $notification->setSubject($reportData['name'] . ' - ID Verification Report');
        $this->lead->owner?->notify($notification);

        foreach ($managers as $manager) {
            $manager->notify($notification);
        }

        $this->generateReportPdf(
            $reportData,
            $isShowRoom,
            $verificationData,
            $parentEngagement,
            $images,
            $reuseExistingEngagement
        );
    }

    protected function generateReportPdf(
        array $reportData,
        bool $isShowRoom,
        array $verificationData,
        ?Engagement $parentEngagement,
        ?array $images,
        bool $reuseExistingEngagement
    ): void {
        try {
            $pdfReport = PdfService::generatePdfFromTemplate(
                $this->lead->app,
                $this->people->user,
                'id-verification-report',
                $this->people,
                [
                   'message' => $reportData['message'],
                   'status' => $reportData['status'],
                   'flags' => $reportData['flags'],
                   'failures' => $reportData['failures'],
                   'results' => $reportData['results'],
                   'isShowRoom' => $isShowRoom,
                   'verificationData' => $verificationData,
                               ]
            );

            $engagement = $this->resolveEngagement($parentEngagement, $reuseExistingEngagement);

            if ($engagement === null) {
                return;
            }

            $this->processDriverLicenseImages(
                engagement: $engagement,
                isIdValid: in_array($reportData['status'], ['green', 'flag']),
                verificationResults: $reportData,
                isExpired: $reportData['status'] === 'flag',
                images: $images,
                parentEngagement: $parentEngagement,
                reuseExistingEngagement: $reuseExistingEngagement
            );
            $engagement->message->addFile($pdfReport, 'id-verification');
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * The folder the UI shows is a root message: `LeadChannelFilesService` groups on
     * `parent_id = 0 OR NULL` and reads the files off the newest submitted child. A report that
     * creates its own root therefore renders as a second folder beside the one holding the licence
     * images, which is why the scan's own engagement has to be threaded under, or reused.
     *
     * `$reuseExistingEngagement` is opt-in so this stays the old always-create behaviour for the
     * callers that predate the folder fix — reusing an engagement moves where their files land, and
     * `VinSolution\Workflow\PushCoBuyerActivity` has no coverage to catch that.
     */
    protected function resolveEngagement(?Engagement $parentEngagement, bool $reuseExistingEngagement): ?Engagement
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

        // An engagement whose message is gone is useless here — every caller dereferences ->message.
        return $existing?->message !== null ? $existing : $this->createEngagement();
    }

    protected function createEngagement(?Engagement $parentEngagement = null): ?Engagement
    {
        // Unassigned leads carry leads_owner_id = 0, so owner is null; the engagement
        // still needs a real user for the message copy and the lead follow.
        $user = $this->lead->owner ?? $this->lead->user;

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
     * Each side resolves to one string — base64 or a filesystem uuid, `resolveFile()` tells them apart.
     * `face` never falls back: a selfie only comes from the Intellicheck payload.
     *
     * Re-linking the parent's file is required, not an optimization: the folder renders the last
     * submitted child's files only (`LeadChannelFilesService::formatMessageFileGroup()`), never the
     * union with the parent, so a side left on the parent disappears from the UI. Per-caller detail in
     * `Connectors/Intellicheck/CLAUDE.md`.
     *
     * @return array<string, ?string>
     */
    protected function resolveImageFields(
        ?array $images,
        ?Engagement $parentEngagement,
        bool $reuseExistingEngagement
    ): array {
        // The custom field travels with the old engagement behaviour on purpose: a caller that threads
        // into an existing folder is the new path, which brings its own images and so must not depend on
        // a field whose write it would have to wait for.
        $fallback = $reuseExistingEngagement ? [] : $this->customFieldImages();
        $resolved = ['face_image' => $images['face'] ?? null];

        // The parent lookup is a query per side, so it stays behind `??`.
        foreach (['front' => 'drivers_license_front', 'back' => 'drivers_license_back'] as $side => $fieldName) {
            $resolved[$fieldName] = $images[$side]
                ?? $this->parentMessageImage($parentEngagement, $fieldName)
                ?? $fallback[$side]
                ?? null;
        }

        return $resolved;
    }

    /**
     * @deprecated The base64 hand-off through `people.driver_license_images`. Nothing in this repo
     *             writes it — an external caller does, and the old path `del()`s it once both sides
     *             attach, so it is a one-shot mailbox rather than a store, and its late arrival is what
     *             `AttachDriverLicenseImagesJob` and the `sleep(20)` were waiting on. Only reached by a
     *             caller that leaves `reuseExistingEngagement` off, which marks the pre-folder-fix
     *             callers. Drop it, and the reads in `IdVerificationReportActivity` and
     *             `AttachDriverLicenseImagesJob`, once `after-id-verification` is gone.
     *
     * Reads but never `del()`s: the two verbs coexist, so clearing the field here would empty the
     * mailbox `after-id-verification` is still waiting on.
     *
     * @return array<string, ?string>
     */
    protected function customFieldImages(): array
    {
        if ($this->customFieldImages === null) {
            $images = $this->people->get('driver_license_images');
            $this->customFieldImages = is_array($images) ? $images : [];
        }

        return $this->customFieldImages;
    }

    /**
     * Returns a uuid, so `resolveFile()` links the existing row instead of paying for a second upload of
     * the same document.
     */
    protected function parentMessageImage(?Engagement $parentEngagement, string $fieldName): ?string
    {
        return $parentEngagement?->message?->getFileByName($fieldName)?->filesystem?->uuid;
    }

    protected function processDriverLicenseImages(
        Engagement $engagement,
        bool $isIdValid,
        array $verificationResults,
        bool $isExpired = false,
        ?array $images = null,
        ?Engagement $parentEngagement = null,
        bool $reuseExistingEngagement = false
    ): void {
        $imageFields = $this->resolveImageFields($images, $parentEngagement, $reuseExistingEngagement);

        foreach ($imageFields as $fieldName => $image) {
            // `addFile` repoints an existing field_name at the new file, so re-running this against an
            // engagement that already holds the customer's own upload would replace it with a second
            // copy of the same document — and pay for another upload to do it.
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
     * A uuid means the file is already uploaded, so it gets linked instead of re-uploaded. Tenant-scoped
     * because the uuid comes from a caller: an unscoped lookup would attach another company's file.
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
