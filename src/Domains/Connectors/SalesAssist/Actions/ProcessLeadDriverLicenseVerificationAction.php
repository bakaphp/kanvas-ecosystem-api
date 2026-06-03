<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Actions;

use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Notification;
use Kanvas\ActionEngine\Engagements\Repositories\EngagementRepository;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intellicheck\Services\IdVerificationService;
use Kanvas\Connectors\SalesAssist\Enums\ConfigurationEnum;
use Kanvas\Connectors\SalesAssist\Services\DriverLicenseVerificationService;
use Kanvas\Filesystem\Models\Filesystem as ModelsFilesystem;
use Kanvas\Filesystem\Services\PdfService;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadParticipant;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;
use Throwable;

class ProcessLeadDriverLicenseVerificationAction
{
    protected ?array $idVerificationReport = null;
    protected ?array $intellicheckResponse = null;
    protected ?DriverLicenseVerificationService $service = null;

    public function __construct(
        protected Lead $lead,
        protected Apps $app,
        protected Companies $company,
        protected Users $user,
        protected array $params = []
    ) {
    }

    public function execute(): array
    {
        try {
            $lockedLead = Lead::where('id', $this->lead->id)
                ->lockForUpdate()
                ->first();

            $lockedLead->set('driver_license_processed', true);

            $results = [];
            $driverLicenseImage = $this->lead->get('driver_license_images');
            $driverLicenseData = $this->lead->get('get_docs_drivers_license');
            $idVerificationData = $this->lead->get('id_verification');
            $participants = $this->resolveParticipants();

            $intellicheckResponse = $this->resolveIntellicheckResponse();
            if ($intellicheckResponse !== null && $intellicheckResponse !== []) {
                $this->intellicheckResponse = $intellicheckResponse;
                $this->idVerificationReport = IdVerificationService::processVerificationData(
                    $intellicheckResponse,
                    $this->lead->people->name,
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

                $this->lead->set('id_verification', $idVerificationData);
            }

            $hasMainDriverLicense = ! empty($driverLicenseImage);
            $hasParticipantDriverLicense = false;

            if (! empty($participants)) {
                foreach ($participants as $participant) {
                    if (isset($participant['driver_license_images'])) {
                        $hasParticipantDriverLicense = true;

                        break;
                    }
                }
            }

            if ($hasMainDriverLicense) {
                $result = $this->processLeadDriverLicense(
                    $this->lead,
                    $driverLicenseImage,
                    $driverLicenseData,
                    $idVerificationData
                );
                $results['lead'] = $result;
            }

            if ($hasParticipantDriverLicense) {
                $participantResults = $this->processParticipants($this->lead, $participants);
                $results['participants'] = $participantResults;
            }

            if (is_array($driverLicenseData) && $hasMainDriverLicense) {
                $this->validateExpirationDate($this->lead, $this->lead->people, $driverLicenseData, $idVerificationData);
            }

            // Notification + PDF only need the intellicheck report — they're
            // independent of having driver-license images on the lead.
            if ($this->intellicheckResponse !== null && $this->idVerificationReport !== null) {
                $this->sendVerificationNotification($this->lead, $this->lead->people);
                $this->generatePdfReport($this->lead);
            }

            $this->cleanupTemporaryData($this->lead);

            if (! $hasMainDriverLicense && ! $hasParticipantDriverLicense) {
                return [
                    'success' => true,
                    'message' => 'No Driver License Image found to process',
                    'results' => [],
                    'intellicheckResponse' => $this->intellicheckResponse ?? null,
                    'idVerificationReport' => $this->idVerificationReport ?? null,
                ];
            }

            return [
                'success' => true,
                'results' => $results,
                'hasMainDriverLicense' => $hasMainDriverLicense,
                'driverLicenseData' => $driverLicenseData,
                'idVerificationData' => $idVerificationData,
                'intellicheckResponse' => $this->intellicheckResponse ?? null,
                'idVerificationReport' => $this->idVerificationReport ?? null,
                'message' => 'Driver license verification completed',
            ];
        } catch (Exception $e) {
            report($e);
            $this->cleanupTemporaryData($this->lead);

            throw $e;
        }
    }

    protected function getService(): DriverLicenseVerificationService
    {
        if ($this->service === null) {
            $this->service = new DriverLicenseVerificationService($this->app, $this->company, $this->user);
        }

        return $this->service;
    }

    protected function resolveIntellicheckResponse(): ?array
    {
        $fromCustomField = $this->lead->get('intellicheckResponse');
        if (is_array($fromCustomField) && $fromCustomField !== []) {
            return $fromCustomField;
        }

        return $this->extractFromLatestAttempt('intellicheckResponse');
    }

    protected function resolveParticipants(): array
    {
        $fromCustomField = $this->lead->get('participants');
        if (is_array($fromCustomField) && $fromCustomField !== []) {
            return $fromCustomField;
        }

        $extracted = $this->extractFromLatestAttempt('participants');

        return is_array($extracted) ? $extracted : [];
    }

    /**
     * Walk the lead's attempts newest-first and pull the named field out of
     * the request payload. Two shapes are accepted:
     *  - Legacy flat:   $request[$name]
     *  - GraphQL input: $request['input']['custom_fields'][N] === ['name' => $name, 'value' => ...]
     */
    protected function extractFromLatestAttempt(string $name): ?array
    {
        foreach ($this->lead->attempts()->latest('id')->get() as $attempt) {
            $request = $attempt->request;
            if (! is_array($request)) {
                continue;
            }

            if (isset($request[$name]) && is_array($request[$name])) {
                return $request[$name];
            }

            $customFields = $request['input']['custom_fields'] ?? null;
            if (is_array($customFields)) {
                foreach ($customFields as $field) {
                    if (is_array($field) && ($field['name'] ?? null) === $name && is_array($field['value'] ?? null)) {
                        return $field['value'];
                    }
                }
            }
        }

        return null;
    }

    protected function processLeadDriverLicense(
        Lead $lead,
        array $driverLicenseImage,
        ?array $driverLicenseData,
        ?array $idVerificationData
    ): array {
        $currentScanOption = $lead->company->get('id_verification') ?? 'intelicheck';
        $isIdValid = (bool) ($idVerificationData[$currentScanOption] ?? false);
        $updatePeopleData = null;

        if ($driverLicenseData) {
            $updatePeopleData = $this->getService()->updatePeopleFromDriverLicense($lead->people, $driverLicenseData);
        }

        $engagement = $this->getService()->createEngagement($lead, $lead->people);
        $message = $engagement->message;

        $isExpired = $this->validateExpirationDate($lead, $lead->people, $driverLicenseData, $idVerificationData);
        $this->getService()->processDriverLicenseImages($message, $driverLicenseImage, $isIdValid, $isExpired);

        return [
            'people_id' => $lead->people->id,
            'engagement_id' => $engagement->getId(),
            'message_id' => $message->getId(),
            'id_valid' => $isIdValid,
            'id_expired' => $isExpired,
            'driverLicenseData' => $driverLicenseData,
            'updatePeopleData' => $updatePeopleData?->toArray(),
        ];
    }

    protected function processParticipants(Lead $lead, array $participants): array
    {
        $results = [];

        foreach ($participants as $participant) {
            if (! isset($participant['driver_license_images'])) {
                continue;
            }

            $idVerificationReport = [];

            if (isset($participant['intellicheckResponse'])) {
                $idVerificationReport = IdVerificationService::processVerificationData(
                    $participant['intellicheckResponse'],
                    IdVerificationService::getName($participant['intellicheckResponse']),
                    true
                );
            }

            $leadParticipant = $this->findLeadParticipant($lead, $participant);
            if (! $leadParticipant) {
                continue;
            }

            $people = $leadParticipant->people;
            $driverLicenseData = $participant['get_docs_drivers_license'] ?? [];
            $idVerificationData = $participant['id_verification'] ?? [];

            if (! empty($idVerificationReport)) {
                $idVerificationData = [
                    'intelicheck' => $idVerificationReport['status'] == 'green' || $idVerificationReport['status'] == 'flag' ? true : false,
                    'status' => $idVerificationReport['status'],
                    'message' => $idVerificationReport['message'],
                    'scandit' => $idVerificationReport['status'] == 'green' || $idVerificationReport['status'] == 'flag' ? true : false,
                    'expired' => $idVerificationReport['status'] == 'flag' ? true : false,
                    'ocMatch' => $idVerificationReport['ocMatch'] ?? false,
                    'intellicheck_workflow_response' => $this->idVerificationReport['status'] === 'green' ? 'passed' : $this->idVerificationReport['status'],
                    'intellicheckResponse' => $this->idVerificationReport['status'] === 'green' ? 'passed' : $this->idVerificationReport['status'],
                ];
            }

            $currentScanOption = $lead->company->get('id_verification') ?? 'intelicheck';
            $isIdValid = (bool) ($idVerificationData[$currentScanOption] ?? false);

            if ($driverLicenseData) {
                $this->getService()->updatePeopleFromDriverLicense($people, $driverLicenseData);
            }

            $isExpired = $this->validateExpirationDate($lead, $people, $driverLicenseData, $idVerificationData, $people->name);

            $engagement = $this->getService()->createEngagement($lead, $people);
            $message = $engagement->message;

            $this->getService()->processDriverLicenseImages($message, $participant['driver_license_images'], $isIdValid, $isExpired);

            $results[] = [
                'people_id' => $people->id,
                'engagement_id' => $engagement->getId(),
                'message_id' => $message->getId(),
                'id_valid' => $isIdValid,
                'id_expired' => $isExpired,
                'participant_name' => $people->name,
            ];
        }

        return $results;
    }

    protected function findLeadParticipant(Lead $lead, array $participant): ?LeadParticipant
    {
        return $lead->participants()
            ->whereHas(
                'people',
                fn ($query) => $query->where('firstname', $participant['firstname'])
                    ->where('lastname', $participant['lastname'])
            )
            ->first();
    }

    protected function validateExpirationDate(
        Lead $lead,
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
            $people->set('id_verification', $idVerificationData);

            if ($people->id === $lead->people->id) {
                $lead->set('id_verification', $idVerificationData);
            }
        }

        return $isExpired;
    }

    protected function sendVerificationNotification(Lead $lead, People $people): void
    {
        $usersToNotify = UsersRepository::findUsersByArray($lead->company->get('company_manager'), $lead->app);
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
            $lead,
        );

        $notification->setSubject($people->name . ' - ID Verification Report');
        Notification::send($usersToNotify, $notification);
    }

    protected function generatePdfReport(Lead $lead): ?ModelsFilesystem
    {
        try {
            $pdfReport = PdfService::generatePdfFromTemplate(
                $lead->app,
                $lead->user,
                'id-verification-report',
                $lead,
                [
                    'message' => $this->idVerificationReport['message'],
                    'status' => $this->idVerificationReport['status'],
                    'flags' => $this->idVerificationReport['flags'],
                    'failures' => $this->idVerificationReport['failures'],
                    'results' => $this->idVerificationReport['results'],
                    'isShowRoom' => true,
                    'verificationData' => $this->intellicheckResponse,
                ]
            );

            $engagement = EngagementRepository::findEngagementForLead(
                $lead,
                ConfigurationEnum::ID_VERIFICATION->value,
                ActionStatusEnum::SUBMITTED->value,
            );

            if ($engagement) {
                $engagement->message?->addFile($pdfReport, 'id-verification');
            } else {
                $lead->addFile($pdfReport, 'id-verification');
            }

            return $pdfReport;
        } catch (Throwable $e) {
            report($e);
        }

        return null;
    }

    protected function cleanupTemporaryData(Lead $lead): void
    {
        $lead->del('driver_license_images');
        $lead->del('participants');
        $lead->del('driver_license_processed');
        $lead->del('intellicheckResponse');
    }
}
