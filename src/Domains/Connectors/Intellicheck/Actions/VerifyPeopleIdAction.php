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

    public function execute(array $verificationData): array
    {
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

        if (! empty($getDocsDriversLicense) && $isLeadPeople) {
            $lead->set('get_docs_drivers_license', $getDocsDriversLicense);
        }
        $people->del('get_docs_drivers_license');

        dispatch(function () use ($lead, $people, $app, $reportData, $isShowRoom, $verificationData, $name) {
            $key = IntegrationsEnum::INTELLICHECK->value . '_sent_report';
            if ($people->get($key)) {
                // If the report has already been sent, we skip the rest of the process
                return;
            }

            $usersToNotify = UsersRepository::findUsersByArray($people->company->get('company_manager'), $app);
            $managers = UsersRepository::getCompanyAppUserByRole($people->company, $app, 'Manager')->get();

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
            Notification::send($usersToNotify, $notification);
            $lead->owner?->notify($notification);

            foreach ($managers as $manager) {
                if ($usersToNotify->contains($manager)) {
                    continue;
                }
                $manager->notify($notification);
            }

            // Generate PDF
            $this->generateReportPdf($reportData, $isShowRoom, $verificationData);

            //$entity->addFile($pdfReport, 'id-verification');

            //since we are running 2 diff version of the api, we need to slow you down to get the last message
        })->delay(now()->addSeconds(30))->onQueue('notifications');

        return [
            'report' => $reportData['status'] === 'green' ? 'passed' : $reportData['status'],
            'result' => true,
            'message' => 'IdVerificationReportActivity executed successfully',
            'data' => $reportData,
            'resultsFromIntellicheck' => $resultsFromIntellicheck,
            'getDocsDriversLicense' => $getDocsDriversLicense ?? null,
        ];
    }

    protected function generateReportPdf(array $reportData, bool $isShowRoom, array $verificationData): void
    {
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
            $message = $engagement->message;
            $message->addFile($pdfReport, 'id-verification');
        } catch (Throwable $e) {
            report($e);
        }
    }

    protected function createEngagement(): Engagement
    {
        $taskId = $this->lead->get('check_list_status') ?? $this->lead->company->get('default_checklist_id');

        $engagementData = new DataTransferObjectEngagement(
            app: $this->lead->app,
            company: $this->lead->company,
            user: $this->lead->owner,
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
}
