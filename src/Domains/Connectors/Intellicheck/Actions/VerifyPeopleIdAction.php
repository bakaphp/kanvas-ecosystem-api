<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intellicheck\Actions;

use Baka\Support\Str;
use Illuminate\Support\Facades\Notification;
use Kanvas\ActionEngine\Engagements\Actions\CreateEngagementAction;
use Kanvas\ActionEngine\Engagements\DataTransferObject\Engagement as DataTransferObjectEngagement;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
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
use Kanvas\Workflow\Enums\IntegrationsEnum;
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
        bool $sendNotification = true
    ): array {
        //$isShowRoom = $params['is_showroom'] ?? false;
        $isShowRoom = ! isset($verificationData['ipqs']);
        // Get person name from lead entity
        $name = IdVerificationService::getName($verificationData);
        $name = $name !== 'Unknown' ? $this->people->name ?? 'Customer' : 'Customer';
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
                lead: $lead,
                people: $people,
                name: $name
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

    protected function sendNotification(
        array $verificationData,
        array $reportData,
        bool $isShowRoom,
        Lead $lead,
        People $people,
        string $name,
    ): void {
        $key = IntegrationsEnum::INTELLICHECK->value . '_sent_report';
        if ($people->get($key)) {
            // If the report has already been sent, we skip the rest of the process
            return;
        }

        //$usersToNotify = UsersRepository::findUsersByArray($people->company->get('company_manager'), $app);
        $managers = UsersRepository::getCompanyAppUserByRole($people->company, $people->app, 'Manager')->get();

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
            $lead,
        );

        $people->set($key, true);
        $notification->setSubject($name . ' - ID Verification Report');
        //Notification::send($usersToNotify, $notification);
        $lead->owner?->notify($notification);

        foreach ($managers as $manager) {
            $manager->notify($notification);
        }

        // Generate PDF
        $this->generateReportPdf(
            $reportData,
            $isShowRoom,
            $verificationData
        );
    }

    protected function generateReportPdf(
        array $reportData,
        bool $isShowRoom,
        array $verificationData,
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

            $engagement = $this->createEngagement();

            if ($engagement === null) {
                return;
            }

            $this->processDriverLicenseImages(
                engagement: $engagement,
                isIdValid: in_array($reportData['status'], ['green', 'flag']),
                verificationResults: $reportData,
                isExpired: $reportData['status'] === 'flag'
            );
            $message = $engagement->message;
            $message->addFile($pdfReport, 'id-verification');
        } catch (Throwable $e) {
            report($e);
        }
    }

    protected function createEngagement(): ?Engagement
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
            requestId: Str::uuid()->toString(),
            source: 'workflow',
            status: ActionStatusEnum::SUBMITTED,
            people: $this->people,
            receiverId: $this->lead->receiver?->getId(),
            taskId: $taskId,
            via: 'webhook',
            data: [],
            //formType: $metadata['form_type'] ?? null,
            //extraField: $metadata['extra_field'] ?? [],
            //extraData: [],
            //channelId: $metadata['channel_id'] ?? null,
        );

        return new CreateEngagementAction($engagementData)->execute();
    }

    protected function processDriverLicenseImages(
        Engagement $engagement,
        bool $isIdValid,
        array $verificationResults,
        bool $isExpired = false
    ): void {
        // Process back image
        $driverLicenseImages = $this->people->get('driver_license_images');
        if (isset($driverLicenseImages['back'])) {
            $backFile = $this->createFileFromBase64($driverLicenseImages['back'], 'drivers_license_back.jpg');
            $engagement->message->addFile($backFile, 'drivers_license_back');

            // Set verification metadata
            $backFile->set('id_verify', (int) $isIdValid);
            $backFile->set('id_expired', (int) $isExpired);
            $backFile->set('id_verification_msg', $verificationResults['message']);
            $backFile->set('id_verification_status', $verificationResults['status'] ?? 'unknown');
        }

        // Process front image
        if (isset($driverLicenseImages['front'])) {
            $frontFile = $this->createFileFromBase64($driverLicenseImages['front'], 'drivers_license_front.jpg');
            $engagement->message->addFile($frontFile, 'drivers_license_front');

            // Set verification metadata
            $frontFile->set('id_verify', (int) $isIdValid);
            $frontFile->set('id_expired', (int) $isExpired);
            $frontFile->set('id_verification_msg', $verificationResults['message']);
            $frontFile->set('id_verification_status', $verificationResults['status'] ?? 'unknown');
        }

        new DriverLicenseCombinedPdfService($engagement->message)->attach(
            $isIdValid,
            $isExpired,
            (string) ($verificationResults['message'] ?? ''),
            (string) ($verificationResults['status'] ?? 'unknown'),
        );
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
