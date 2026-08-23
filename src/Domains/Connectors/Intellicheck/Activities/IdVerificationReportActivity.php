<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intellicheck\Activities;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Kanvas\ActionEngine\Engagements\Actions\CreateEngagementAction;
use Kanvas\ActionEngine\Engagements\DataTransferObject\Engagement as EngagementData;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Connectors\Intellicheck\Jobs\AttachDriverLicenseImagesJob;
use Kanvas\Connectors\Intellicheck\Services\IdVerificationService;
use Kanvas\Connectors\SalesAssist\Enums\ConfigurationEnum;
use Kanvas\Connectors\SalesAssist\Services\DriverLicenseVerificationService;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Filesystem\Services\PdfService;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Repositories\UsersRepository;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;
use Throwable;

#[WorkflowAction]
class IdVerificationReportActivity extends KanvasActivity implements WorkflowActivityInterface
{
    public $tries = 3;

    #[Override]
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        try {
            $verificationData = $params;
            $isShowRoom = ! isset($verificationData['ipqs']);

            $name = IdVerificationService::getName($verificationData);
            $name = $name !== 'Unknown' ? $name : ($entity->title ?? ($entity->people->name ?? 'Customer'));

            $verificationResults = IdVerificationService::processVerificationData($verificationData, $name, $isShowRoom);
            $company = $entity->company;

            /**
             * @todo move to use the idverification action
             */
            return $this->executeIntegration(
                entity: $entity,
                app: $app,
                integration: IntegrationsEnum::INTELLICHECK,
                integrationOperation: function ($entity, $app) use ($name, $verificationResults, $verificationData, $isShowRoom) {
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

                    // A co-buyer/participant scan carries its own peopleId in the payload
                    // ($params['participant']['peopleId']); a main-buyer scan does not.
                    // Resolve the person actually verified so a participant's result never
                    // overwrites the lead's main people (and its lead-level DL slots).
                    $lead = $entity;
                    $verifiedPeople = $entity instanceof Lead
                        ? $this->resolveVerifiedPeople($entity, $verificationData, $app)
                        : null;
                    $isLeadPeople = $verifiedPeople !== null && $verifiedPeople->getId() === $lead->people_id;

                    if ($verifiedPeople !== null) {
                        $verifiedPeople->set('id_verification', $resultsFromIntellicheck);

                        if (! empty($getDocsDriversLicense)) {
                            // Persist before dropping the custom field below.
                            new DriverLicenseVerificationService(
                                $lead->app,
                                $verifiedPeople->company,
                                $lead->user,
                            )->updatePeopleFromDriverLicense($verifiedPeople, $getDocsDriversLicense);

                            $verifiedPeople->del('get_docs_drivers_license');
                        }
                    }

                    // The lead's own DL/verification slots only ever describe the main buyer.
                    if ($isLeadPeople) {
                        $lead->set('id_verification', $resultsFromIntellicheck);

                        if (! empty($getDocsDriversLicense)) {
                            $lead->set('get_docs_drivers_license', $getDocsDriversLicense);
                        }
                    }

                    $sendEmailNotification = (bool) $entity->company->get('disable_id_verification_email', false) === false;

                    sleep(20); // let prior activities (image/DL processing) settle before re-reading the entity
                    $entity->refresh();

                    // Dedup per verified person, not per display name: a participant with a
                    // blurry doc resolves $name back to the lead/main-buyer name, so a
                    // name-keyed cache would make the participant and main buyer collide and
                    // silently skip each other within the 3-minute window.
                    $verificationSubjectKey = $verifiedPeople !== null
                        ? (string) $verifiedPeople->getId()
                        : Str::simpleSlug($name);

                    $entity->set(IntegrationsEnum::INTELLICHECK->value . '_sent_report_' . $verificationSubjectKey, true);
                    $cacheKey = 'intellicheck_report_' . $entity->getId() . '_' . $verificationSubjectKey;

                    $generatePdf = 'Generate PDF report for Intellicheck ID Verification';

                    // Guard the whole report as do-once: each verification gets its own fresh
                    // engagement, so a retry must not spawn a second empty one.
                    if (! Cache::has($cacheKey)) {
                        Cache::put($cacheKey, true, now()->addMinutes(3));

                        $usersToNotify = UsersRepository::findUsersByArray($entity->company->get('company_manager'), $app);
                        $managers = UsersRepository::getCompanyAppUserByRole($entity->company, $entity->app, 'Manager')->get();

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
                            $entity,
                        );

                        if ($sendEmailNotification) {
                            $notification->setSubject($name . ' - ID Verification Report');
                            Notification::send($usersToNotify, $notification);

                            $entity->owner?->notify($notification);

                            foreach ($managers as $manager) {
                                if ($usersToNotify->contains($manager)) {
                                    continue;
                                }
                                $manager->notify($notification);
                            }
                        }

                        try {
                            if ($entity instanceof Lead && $verifiedPeople !== null) {
                                $engagement = $this->createIdVerificationEngagement(
                                    $entity,
                                    $verifiedPeople
                                );

                                $message = $engagement?->message;
                                if ($message !== null) {
                                    $pdfReport = PdfService::generatePdfFromTemplate(
                                        $app,
                                        $entity->user,
                                        'id-verification-report',
                                        $entity,
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
                                    $message->addFile($pdfReport, 'id-verification');

                                    $this->attachDriverLicenseImagesIfMissing(
                                        $message,
                                        $verifiedPeople,
                                        $reportData
                                    );

                                    // The base64 usually lands on the person a few seconds
                                    // after this runs, so the inline attach above loses the
                                    // race. A delayed idempotent job re-attaches once it's
                                    // written and clears the temp fields.
                                    AttachDriverLicenseImagesJob::dispatch(
                                        $app,
                                        $verifiedPeople,
                                        $message,
                                        (string) ($reportData['status'] ?? 'unknown'),
                                        (string) ($reportData['message'] ?? ''),
                                    )->delay(now()->addSeconds(30));
                                }
                            }
                        } catch (Throwable $e) {
                            report($e);
                            $generatePdf .= ' - Error generating PDF: ' . $e->getMessage();
                        }
                    }

                    return [
                        'report' => $reportData['status'] === 'green' ? 'passed' : $reportData['status'],
                        'result' => true,
                        'message' => 'IdVerificationReportActivity executed successfully',
                        'data' => $reportData,
                        'resultsFromIntellicheck' => $resultsFromIntellicheck,
                        'getDocsDriversLicense' => $getDocsDriversLicense ?? null,
                        'generatePdf' => $generatePdf,
                        'engagement_id' => isset($engagement) ? $engagement->getId() : null,
                    ];
                },
                company: $company,
            );
        } catch (Throwable $e) {
            return [
                'report' => 'fail',
                'result' => false,
                'message' => 'Error processing ID verification: ' . $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ];
        }
    }

    /**
     * A participant/co-buyer verification carries the participant's own id in
     * $params['participant']['peopleId']; a main-buyer verification omits it. Resolve
     * that person (tenant-scoped) so a participant's result never lands on the main
     * people; fall back to the lead's main people when there is no participant id.
     */
    /**
     * Idempotent; drops the base64 only once both submitted sides are confirmed attached,
     * so a run that races the base64 write never loses the images.
     */
    private function attachDriverLicenseImagesIfMissing(Message $message, People $people, array $reportData): void
    {
        $images = $people->get('driver_license_images');
        if (! is_array($images) || (empty($images['front']) && empty($images['back']))) {
            return;
        }

        $isIdValid = in_array($reportData['status'] ?? null, ['green', 'flag'], true);
        $isExpired = ($reportData['status'] ?? null) === 'flag';
        $filesystemService = new FilesystemServices($people->app, $people->company);

        foreach (['back' => 'drivers_license_back', 'front' => 'drivers_license_front'] as $side => $field) {
            if (empty($images[$side]) || $message->getFileByName($field) !== null) {
                continue;
            }

            $file = $filesystemService->createFileSystemFromBase64(
                $images[$side],
                $field . '.jpg',
                $people->user
            );
            $message->addFile($file, $field);

            $file->set('id_verify', (int) $isIdValid);
            $file->set('id_expired', (int) $isExpired);
            $file->set('id_verification_msg', $reportData['message'] ?? '');
            $file->set('id_verification_status', $reportData['status'] ?? 'unknown');
        }

        $frontDone = empty($images['front']) || $message->getFileByName('drivers_license_front') !== null;
        $backDone = empty($images['back']) || $message->getFileByName('drivers_license_back') !== null;

        if ($frontDone && $backDone) {
            $people->del('driver_license_images');
        }
    }

    private function resolveVerifiedPeople(Lead $lead, array $params, AppInterface $app): People
    {
        $participantPeopleId = $params['participant']['peopleId'] ?? null;

        if ($participantPeopleId !== null) {
            /** @var People|null $participant */
            $participant = People::fromCompany($lead->company)
                ->fromApp($app)
                ->where('id', (int) $participantPeopleId)
                ->notDeleted()
                ->first();

            if ($participant !== null) {
                return $participant;
            }
        }

        return $lead->people;
    }

    private function createIdVerificationEngagement(Lead $lead, People $people): ?Engagement
    {
        // Unassigned leads carry leads_owner_id = 0, so owner is null; the engagement
        // still needs a real user for the message copy and the lead follow.
        $user = $lead->owner ?? $lead->user ?? $people->user;

        if ($user === null) {
            return null;
        }

        $taskId = $lead->get('check_list_status') ?? $lead->company->get('default_checklist_id');

        if (is_array($taskId)) {
            $taskId = $taskId['activeTaskListId'] ?? $lead->company->get('default_checklist_id');
        }

        $engagementData = new EngagementData(
            app: $lead->app,
            company: $lead->company,
            user: $user,
            lead: $lead,
            action: ConfigurationEnum::ID_VERIFICATION->value,
            requestId: Str::uuid()->toString(),
            source: 'workflow',
            status: ActionStatusEnum::SUBMITTED,
            people: $people,
            receiverId: $lead->receiver?->getId(),
            taskId: $taskId,
            via: 'webhook',
        );

        return new CreateEngagementAction($engagementData)->execute();
    }
}
