<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intellicheck\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;
use Kanvas\ActionEngine\Engagements\Repositories\EngagementRepository;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Connectors\Intellicheck\Services\IdVerificationService;
use Kanvas\Connectors\Intellicheck\Services\PeopleService;
use Kanvas\Connectors\SalesAssist\Enums\ConfigurationEnum;
use Kanvas\Filesystem\Services\PdfService;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Users\Repositories\UsersRepository;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;
use Throwable;

class IdVerificationReportActivity extends KanvasActivity implements WorkflowActivityInterface
{
    public $tries = 3;

    #[Override]
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        try {
            // Extract verification data from params
            $verificationData = $params;

            $isShowRoom = $params['is_showroom'] ?? false;

            // Get person name from lead entity
            $name = $entity->title ?? $entity->people->name ?? 'Customer';

            // Process data to generate verification results
            $verificationResults = IdVerificationService::processVerificationData($verificationData, $name, $isShowRoom);
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

                    dispatch(function () use ($entity, $app, $reportData, $isShowRoom, $verificationData) {
                        //since we are running 2 diff version of the api, we need to slow you down to get the last message
                        sleep(30);

                        $usersToNotify = UsersRepository::findUsersByArray($entity->company->get('company_manager'), $app);
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

                        $notification->setSubject('ID Verification Report');
                        Notification::send($usersToNotify, $notification);

                        // Generate PDF
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

                            if ($engagement) {
                                //update people name
                                /*                                 if ($engagement->people instanceof People) {
                                                                    PeopleService::updatePeopleInformation($engagement->people, $verificationData);
                                                                } */

                                $message = $engagement->message;
                                $message->addFile($pdfReport, 'id-verification');
                            }
                        }

                        //$entity->addFile($pdfReport, 'id-verification');
                    });

                    return [
                        'report' => $reportData['status'] === 'green' ? 'passed' : $reportData['status'],
                        'result' => true,
                        'message' => 'IdVerificationReportActivity executed successfully',
                        'data' => $reportData,
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
}
