<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Actions;

use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Kanvas\Connectors\Intellicheck\Services\IdVerificationService;
use Kanvas\Connectors\SalesAssist\Services\DriverLicenseVerificationService;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Repositories\UsersRepository;

class ProcessPeopleDriverLicenseVerificationAction
{
    protected ?array $idVerificationReport = null;
    protected ?array $intellicheckResponse = null;
    protected ?DriverLicenseVerificationService $service = null;
    protected ?Message $engagementMessage = null;

    public function __construct(
        protected People $people,
        protected array $params = [],
        protected ?Lead $lead = null,
    ) {
    }

    public function execute(): array
    {
        DB::beginTransaction();

        $lead = $this->lead ?? LeadsRepository::getPeopleActiveLead($this->people);

        if ($lead === null) {
            return [
                'success' => false,
                'message' => 'No active lead found for this person',
            ];
        }

        try {
            $this->people->set('driver_license_processed_activity', true);

            $results = [];
            $driverLicenseImage = $this->people->get('driver_license_images');
            $driverLicenseData = $this->people->get('get_docs_drivers_license');
            $idVerificationData = $this->people->get('id_verification');

            $intellicheckResponse = $this->people->get('intellicheckResponse');
            if (is_array($intellicheckResponse) && $intellicheckResponse !== []) {
                $this->intellicheckResponse = $intellicheckResponse;
                $this->idVerificationReport = IdVerificationService::processVerificationData(
                    $intellicheckResponse,
                    $this->people->name,
                    true
                );
                $this->getService()->setIdVerificationReport($this->idVerificationReport);

                $idVerificationData = [
                    'intelicheck' => $this->idVerificationReport['status'] == 'green' || $this->idVerificationReport['status'] == 'flag' ? true : false,
                    'status' => $this->idVerificationReport['status'],
                    'message' => $this->idVerificationReport['message'],
                    'scandit' => $this->idVerificationReport['status'] == 'green' || $this->idVerificationReport['status'] == 'flag' ? true : false,
                    'expired' => $this->idVerificationReport['status'] == 'flag' ? true : false,
                    'ocMatch' => $this->idVerificationReport['ocMatch'] ?? false,
                    'intellicheck_workflow_response' => $this->idVerificationReport['status'] === 'green' ? 'passed' : $this->idVerificationReport['status'],
                    'intellicheckResponse' => $this->idVerificationReport['status'] === 'green' ? 'passed' : $this->idVerificationReport['status'],
                ];

                $this->people->set('id_verification', $idVerificationData);
            }

            $hasMainDriverLicense = ! empty($driverLicenseImage);

            if ($hasMainDriverLicense) {
                $result = $this->processLeadDriverLicense(
                    $lead,
                    $driverLicenseImage,
                    $driverLicenseData,
                    $idVerificationData
                );
                $results['lead'] = $result;
            }

            if (is_array($driverLicenseData) && $hasMainDriverLicense) {
                $this->validateExpirationDate($this->people, $driverLicenseData, $idVerificationData);
            }

            if ($this->intellicheckResponse !== null && $this->idVerificationReport !== null) {
                $this->sendVerificationNotification();
                $this->getService()->generateIdVerificationPdf(
                    $lead,
                    $this->people,
                    $this->idVerificationReport,
                    $this->intellicheckResponse,
                    $this->engagementMessage
                );
            }

            $this->cleanupTemporaryData();

            DB::commit();

            return [
                'success' => true,
                'results' => $results,
                'driverLicenseData' => $driverLicenseData,
                'idVerificationData' => $idVerificationData,
                'intellicheckResponse' => $this->intellicheckResponse ?? null,
                'idVerificationReport' => $this->idVerificationReport ?? null,
                'message' => 'Driver license verification completed',
            ];
        } catch (Exception $e) {
            DB::rollBack();
            report($e);
            $this->cleanupTemporaryData();

            throw $e;
        }
    }

    protected function getService(): DriverLicenseVerificationService
    {
        if ($this->service === null) {
            $this->service = new DriverLicenseVerificationService(
                $this->people->app,
                $this->people->company,
                $this->people->user,
            );
        }

        return $this->service;
    }

    protected function processLeadDriverLicense(
        Lead $lead,
        array $driverLicenseImage,
        ?array $driverLicenseData,
        ?array $idVerificationData
    ): array {
        $currentScanOption = $lead->company->get('id_verification') ?? 'intelicheck';
        $isIdValid = (bool) ($idVerificationData[$currentScanOption] ?? false);

        if ($driverLicenseData) {
            $this->getService()->updatePeopleFromDriverLicense($this->people, $driverLicenseData);
        }

        $engagement = $this->getService()->createEngagement($lead, $this->people);
        $message = $engagement->message;
        $this->engagementMessage = $message;

        $isExpired = $this->validateExpirationDate($this->people, $driverLicenseData, $idVerificationData);
        $this->getService()->processDriverLicenseImages($message, $driverLicenseImage, $isIdValid, $isExpired);

        return [
            'people_id' => $this->people->id,
            'engagement_id' => $engagement->getId(),
            'message_id' => $message->getId(),
            'id_valid' => $isIdValid,
            'id_expired' => $isExpired,
        ];
    }

    protected function validateExpirationDate(
        People $people,
        ?array $driverLicenseData = null,
        ?array $idVerificationData = null,
        ?string $participantName = null
    ): bool {
        if (empty($driverLicenseData) || ! isset($driverLicenseData['exp_date'])) {
            return false;
        }

        $expDate = $driverLicenseData['exp_date'];
        $expirationDate = Carbon::createFromFormat('Y-m-d', sprintf(
            '%04d-%02d-%02d',
            $expDate['year'],
            $expDate['month'],
            $expDate['day']
        ));

        $isExpired = $expirationDate->isPast();

        if ($idVerificationData) {
            if (! isset($idVerificationData['expired'])) {
                $idVerificationData['expired'] = $isExpired;
            }
            $this->people->set('id_verification', $idVerificationData);
        }

        return $isExpired;
    }

    protected function sendVerificationNotification(): void
    {
        $usersToNotify = UsersRepository::findUsersByArray(
            $this->people->company->get('company_manager'),
            $this->people->app
        );

        $notification = new Blank(
            'id-verification-report',
            [
                'message' => $this->idVerificationReport['message'],
                'status' => $this->idVerificationReport['status'],
                'flags' => $this->idVerificationReport['flags'],
                'failures' => $this->idVerificationReport['failures'],
                'results' => $this->idVerificationReport['results'],
                'isShowRoom' => true,
                'verificationData' => $this->intellicheckResponse,
            ],
            ['mail'],
            $this->people,
        );

        $notification->setSubject($this->people->name . ' - ID Verification Report');
        Notification::send($usersToNotify, $notification);
    }

    protected function cleanupTemporaryData(): void
    {
        $this->people->del('driver_license_images');
        $this->people->del('participants');
        $this->people->del('driver_license_processed');
        $this->people->del('intellicheckResponse');
    }
}
