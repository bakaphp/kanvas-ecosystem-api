<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intellicheck\Activities;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Kanvas\ActionEngine\Engagements\Repositories\EngagementRepository;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Connectors\Intellicheck\Services\IdVerificationService;
use Kanvas\Connectors\SalesAssist\Enums\ConfigurationEnum;
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
            // Extract verification data from params
            $verificationData = $params;

            //$isShowRoom = $params['is_showroom'] ?? false;
            $isShowRoom = ! isset($verificationData['ipqs']);
            // Get person name from lead entity
            $name = IdVerificationService::getName($verificationData);
            $name = $name !== 'Unknown' ? $name : ($entity->title ?? ($entity->people->name ?? 'Customer'));

            // Process data to generate verification results
            $verificationResults = IdVerificationService::processVerificationData(
                $verificationData,
                $name,
                $isShowRoom
            );
            $company = $entity->company;

            // Generate report HTML using the template
            // $reportHtml = $this->generateIntellicheckReport(
            //     $verificationResults['message'],
            //     $verificationData,
            //     $verificationResults['status'],
            //     $verificationResults['results'],
            //     $verificationResults['failures'],
            //     $verificationResults['flags']
            // );

            // Prepare data to pass to the Blade template

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

                    // Create getDocsDriversLicense data from verification data
                    $getDocsDriversLicense = null;
                    if (isset($verificationData['idcheck']['data'])) {
                        $idCheck = $verificationData['idcheck']['data'];
                        $ocrMatchData = $verificationData['ocr_match']['data'] ?? [];

                        $getDocsDriversLicense = [
                            'address' => $ocrMatchData['address'] ?? '',
                            'state' => $idCheck['state'] ?? '',
                            'birthday' => [
                                'day' => isset($idCheck['dateOfBirth']) ? (int) date('d', strtotime($idCheck['dateOfBirth'])) : 0,
                                'month' => isset($idCheck['dateOfBirth']) ? (int) date('m', strtotime($idCheck['dateOfBirth'])) : 0,
                                'year' => isset($idCheck['dateOfBirth']) ? (int) date('Y', strtotime($idCheck['dateOfBirth'])) : 0,
                            ],
                            'license' => $idCheck['dLIDNumberRaw'] ?? '',
                            'exp_date' => [
                                'day' => isset($idCheck['expirationDate']) && is_numeric($idCheck['expirationDate']) ? (int) date('d', strtotime($idCheck['expirationDate'])) : 0,
                                'month' => isset($idCheck['expirationDate']) && is_numeric($idCheck['expirationDate']) ? (int) date('m', strtotime($idCheck['expirationDate'])) : 0,
                                'year' => isset($idCheck['expirationDate']) && is_numeric($idCheck['expirationDate']) ? (int) date('Y', strtotime($idCheck['expirationDate'])) : 0,
                            ],
                            'state_id' => 0,
                            'firstname' => $idCheck['firstName'] ?? '',
                            'middlename' => '',
                            'lastname' => $idCheck['lastName'] ?? '',
                        ];
                    }

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

                    // Resolve the person the verification payload actually belongs to
                    // (main buyer vs. a co-buyer/participant). Without this a co-buyer scan
                    // routed through this lead-scoped activity overwrites the lead's main
                    // people. Mirrors the guard in VerifyPeopleIdAction::execute().
                    $lead = $entity;
                    $verifiedPeople = $entity instanceof Lead
                        ? $this->resolveVerifiedPeople($entity, $getDocsDriversLicense)
                        : null;
                    $isLeadPeople = $verifiedPeople !== null && $verifiedPeople->getId() === $lead->people_id;

                    if ($verifiedPeople !== null) {
                        $verifiedPeople->set('id_verification', $resultsFromIntellicheck);

                        if (! empty($getDocsDriversLicense)) {
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

                    //dispatch(function () use ($entity, $app, $reportData, $isShowRoom, $verificationData, $name) {
                    sleep(5); // Delay to ensure previous processes are complete
                    $entity->refresh();

                    // Use Redis cache to prevent duplicate execution within 3 minutes
                    $entity->set(IntegrationsEnum::INTELLICHECK->value . '_sent_report_' . Str::simpleSlug($name), true);
                    $cacheKey = 'intellicheck_report_' . $entity->getId() . '_' . Str::simpleSlug($name);
                    if (Cache::has($cacheKey)) {
                        // If the report has already been sent, we skip the rest of the process
                        return [
                            'report' => $reportData['status'] === 'green' ? 'passed' : $reportData['status'],
                            'result' => true,
                            'message' => 'IdVerificationReportActivity already executed',
                            'data' => $reportData,
                            'resultsFromIntellicheck' => $resultsFromIntellicheck,
                            'getDocsDriversLicense' => $getDocsDriversLicense ?? null,
                        ];
                    }

                    // Set cache for 3 minutes
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
                    // Generate PDF
                    $generatePdf = 'Generate PDF report for Intellicheck ID Verification';

                    try {
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

                        if ($entity instanceof Lead) {
                            $engagement = EngagementRepository::findEngagementForLead(
                                $entity,
                                ConfigurationEnum::ID_VERIFICATION->value,
                                ActionStatusEnum::SUBMITTED->value,
                            );

                            $engagement?->message?->addFile(
                                $pdfReport,
                                'id-verification'
                            );
                        }

                        //$entity->addFile($pdfReport, 'id-verification');
                    } catch (Throwable $e) {
                        report($e);
                        $generatePdf .= ' - Error generating PDF: ' . $e->getMessage();
                        // Log PDF generation error but continue
                    }

                    //since we are running 2 diff version of the api, we need to slow you down to get the last message
                    //})->delay(now()->addSeconds(30))->onQueue('notifications');

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
     * The verification payload can belong to the main buyer or to a co-buyer/participant.
     * Prefer a confident participant match so a co-buyer scan never lands on the main
     * people; fall back to the lead's main people (legacy behavior) when nothing matches.
     */
    private function resolveVerifiedPeople(Lead $lead, ?array $getDocsDriversLicense): People
    {
        $license = trim((string) ($getDocsDriversLicense['license'] ?? ''));
        $firstname = trim((string) ($getDocsDriversLicense['firstname'] ?? ''));
        $lastname = trim((string) ($getDocsDriversLicense['lastname'] ?? ''));

        if ($license === '' && ($firstname === '' || $lastname === '')) {
            return $lead->people;
        }

        foreach ($lead->participants()->with('people')->get() as $participant) {
            $candidate = $participant->people;

            if ($candidate !== null
                && $candidate->getId() !== $lead->people_id
                && $this->peopleMatchesLicense($candidate, $license, $firstname, $lastname)
            ) {
                return $candidate;
            }
        }

        return $lead->people;
    }

    private function peopleMatchesLicense(People $people, string $license, string $firstname, string $lastname): bool
    {
        $peopleLicense = trim($people->license_number);

        if ($license !== '' && $peopleLicense !== '') {
            return strcasecmp($peopleLicense, $license) === 0;
        }

        if ($firstname !== '' && $lastname !== '') {
            return strcasecmp(trim($people->firstname ?? ''), $firstname) === 0
                && strcasecmp(trim($people->lastname ?? ''), $lastname) === 0;
        }

        return false;
    }
}
