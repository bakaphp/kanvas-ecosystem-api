<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Actions;

use Baka\Support\Str;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Kanvas\ActionEngine\Actions\Models\Action;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intellicheck\Services\IdVerificationService;
use Kanvas\Connectors\SalesAssist\Enums\ConfigurationEnum;
use Kanvas\Filesystem\Models\Filesystem as ModelsFilesystem;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Guild\Customers\Actions\UpdatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\Address as DataTransferObjectAddress;
use Kanvas\Guild\Customers\DataTransferObject\Contact as DataTransferObjectContact;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleDataInput;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadParticipant;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;
use Spatie\LaravelData\DataCollection;

class ProcessDriverLicenseVerificationAction
{
    protected ?array $idVerificationReport = null;
    protected ?array $intellicheckResponse = null;

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
        DB::beginTransaction();

        try {
            // Lock the lead to prevent concurrent processing
            $lockedLead = Lead::where('id', $this->lead->id)
                ->lockForUpdate()
                ->first();

            if ($lockedLead->get('driver_license_processed')) {
                DB::commit();

                return [
                    'success' => false,
                    'message' => 'Driver license already processed',
                ];
            }

            $lockedLead->set('driver_license_processed', true);

            $results = [];
            $driverLicenseImage = $this->lead->get('driver_license_images');
            $driverLicenseData = $this->lead->get('get_docs_drivers_license');
            $idVerificationData = $this->lead->get('id_verification');
            $participants = $this->lead->get('participants') ?? [];

            // Set intellicheck response if available
            if (! empty($this->lead->get('intellicheckResponse'))) {
                $this->intellicheckResponse = IdVerificationService::processVerificationData(
                    $this->lead->get('intellicheckResponse'),
                    $this->lead->people->name,
                    true
                );
            }

            // Check if there are any driver license images to process
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

            // If no driver license images found, return early
            if (! $hasMainDriverLicense && ! $hasParticipantDriverLicense) {
                DB::commit();

                return [
                    'success' => true,
                    'message' => 'No Driver License Image found to process',
                    'results' => [],
                ];
            }

            // Process main lead driver license
            if ($hasMainDriverLicense) {
                $result = $this->processLeadDriverLicense(
                    $this->lead,
                    $this->app,
                    $driverLicenseImage,
                    $driverLicenseData,
                    $idVerificationData
                );
                $results['lead'] = $result;
            }

            // Process participants (co-buyers)
            if ($hasParticipantDriverLicense) {
                $participantResults = $this->processParticipants($this->lead, $this->app, $participants);
                $results['participants'] = $participantResults;
            }

            // Validate expiration dates - only if we have driver license data
            if ($driverLicenseData && $hasMainDriverLicense) {
                $this->validateExpirationDate($this->lead, $this->lead->people, $driverLicenseData, $idVerificationData);
            }

            // Clean up temporary data
            $this->cleanupTemporaryData($this->lead);

            DB::commit();

            return [
                'success' => true,
                'results' => $results,
                'driverLicenseData' => $driverLicenseData,
                'idVerificationData' => $idVerificationData,
                'message' => 'Driver license verification completed',
            ];
        } catch (Exception $e) {
            DB::rollBack();
            report($e);

            throw $e;
        }
    }

    protected function processLeadDriverLicense(
        Lead $lead,
        Apps $app,
        array $driverLicenseImage,
        ?array $driverLicenseData,
        ?array $idVerificationData
    ): array {
        $currentScanOption = $lead->company->get('id_verification') ?? 'intelicheck';
        $isIdValid = (bool) ($idVerificationData[$currentScanOption] ?? false);

        // Update people information from driver license data
        if ($driverLicenseData) {
            $this->updatePeopleFromDriverLicense($lead->people, $driverLicenseData);
        }

        // Create engagement and message
        $engagement = $this->createEngagement($lead, $lead->people, $app);
        $message = $engagement->message;

        // Process and upload images
        $isExpired = $this->validateExpirationDate($lead, $lead->people, $driverLicenseData, $idVerificationData) ?? false;
        $this->processDriverLicenseImages($message, $driverLicenseImage, $isIdValid, $isExpired);

        return [
            'people_id' => $lead->people->id,
            'engagement_id' => $engagement->getId(),
            'message_id' => $message->getId(),
            'id_valid' => $isIdValid,
            'id_expired' => $isExpired,
        ];
    }

    protected function processParticipants(Lead $lead, Apps $app, array $participants): array
    {
        $results = [];

        foreach ($participants as $index => $participant) {
            if (! isset($participant['driver_license_images'])) {
                continue;
            }

            // Set participant intellicheck response if available
            if (isset($participant['intellicheckResponse'])) {
                //$this->intellicheckResponse = $participant['intellicheckResponse'];
                $this->intellicheckResponse = IdVerificationService::processVerificationData(
                    $participant['intellicheckResponse'],
                    IdVerificationService::getName($participant['intellicheckResponse']),
                    true
                );
            }

            // Find the corresponding participant
            $leadParticipant = $this->findLeadParticipant($lead, $participant);
            if (! $leadParticipant) {
                continue;
            }

            $people = $leadParticipant->people;
            $driverLicenseData = $participant['get_docs_drivers_license'] ?? [];
            $idVerificationData = $participant['id_verification'] ?? [];

            $currentScanOption = $lead->company->get('id_verification') ?? 'intelicheck';
            $isIdValid = (bool) ($idVerificationData[$currentScanOption] ?? false);

            // Update participant information from driver license data
            if ($driverLicenseData) {
                $this->updatePeopleFromDriverLicense($people, $driverLicenseData);
            }

            // Validate expiration date
            $isExpired = $this->validateExpirationDate($lead, $people, $driverLicenseData, $idVerificationData, $people->name);

            // Create engagement and message
            $engagement = $this->createEngagement($lead, $people, $app);
            $message = $engagement->message;

            // Process and upload images
            $this->processDriverLicenseImages($message, $participant['driver_license_images'], $isIdValid, $isExpired);

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
            ->whereHas('people', function ($query) use ($participant) {
                $query->where('firstname', $participant['firstname'])
                    ->where('lastname', $participant['lastname']);
            })
            ->first();
    }

    protected function updatePeopleFromDriverLicense(People $people, array $driverLicenseData): void
    {
        // Parse address components
        $addressComponents = isset($driverLicenseData['address']) ?
            $this->parseAddress($driverLicenseData['address']) : null;

        // Build address array
        $addressArray = [];

        if ($addressComponents) {
            $addressArray[] = [
                'address' => $addressComponents['address'] ?? '',
                'city' => $addressComponents['city'] ?? '',
                'state' => $addressComponents['state'] ?? '',
                'zip' => $addressComponents['zipcode'] ?? '',
                'country' => 'United States',
                'address_2' => null,
                'is_default' => true,
            ];
        }

        // Parse birth date
        $dob = null;
        if (isset($driverLicenseData['birthday'])) {
            $birthday = $driverLicenseData['birthday'];
            $birthDateString = sprintf(
                '%04d-%02d-%02d',
                $birthday['year'],
                $birthday['month'],
                $birthday['day']
            );
            if ($this->isValidDate($birthDateString)) {
                $dob = Carbon::createFromFormat('Y-m-d', $birthDateString);
            }
        }

        // Build People DTO
        $peopleData = new PeopleDataInput(
            app: $this->app,
            branch: $people->company->defaultBranch,
            user: $this->user,
            firstname: $driverLicenseData['firstname'] ?? $people->firstname,
            lastname: $driverLicenseData['lastname'] ?? $people->lastname,
            middlename: $driverLicenseData['middlename'] ?? $people->middlename,
            dob: $dob,
            contacts: DataTransferObjectContact::collect([], DataCollection::class),
            address: DataTransferObjectAddress::collect($addressArray, DataCollection::class),
            id: $people->id,
            custom_fields: [],
            tags: []
        );

        // Use UpdatePeopleAction to handle all updates
        $updateAction = new UpdatePeopleAction($people, $peopleData);
        $people = $updateAction->execute();

        if (! empty($driverLicenseData['license'])) {
            // Set the driver's license number
            $people->set('drivers_license_number', $driverLicenseData['license']);
        }
    }

    protected function createEngagement(Lead $lead, People $people, Apps $app): Engagement
    {
        $action = Action::getBySlug(ConfigurationEnum::ID_VERIFICATION->value, $lead->company);
        $companyAction = CompanyAction::getByAction($action, $lead->company, $app);

        // Get the pipeline stage
        $pipeline = $companyAction->pipeline;
        $stage = $pipeline->stages()->where('slug', 'submitted')->firstOrFail();

        // Create message for the engagement
        $messageType = MessageType::fromApp($app)
            ->where('verb', ConfigurationEnum::ID_VERIFICATION->value)
            ->first();

        if (! $messageType) {
            $messageType = (new CreateMessageTypeAction(
                new MessageTypeInput(
                    $app,
                    1,
                    'ID Verification',
                    ConfigurationEnum::ID_VERIFICATION->value,
                )
            ))->execute();
        }

        $messageInput = new MessageInput(
            app: $app,
            company: $lead->company,
            user: $lead->user,
            type: $messageType,
            message: [
                'engagement_status' => 'submitted',
                'hashtagVisited' => ConfigurationEnum::ID_VERIFICATION->value,
                'text' => 'ID Verification Showroom',
                'source' => 'workflow',
                'status' => 'submitted',
                'verb' => ConfigurationEnum::ID_VERIFICATION->value,
            ]
        );

        $message = new CreateMessageAction(
            $messageInput,
            SystemModulesRepository::getByModelName(Lead::class),
            $lead->getId()
        )->execute();
        $message->set('people_id', $people->id);
        $message->saveOrFail();

        $lead->socialChannels->first()->addMessage(
            $message,
            $lead->user
        );

        // Create the engagement using the correct pattern
        $engagement = Engagement::firstOrCreate([
            'companies_id' => $lead->company->getId(),
            'apps_id' => $app->getId(),
            'users_id' => $lead->user->getId(),
            'leads_id' => $lead->getId(),
            'people_id' => $people->getId(),
            'companies_actions_id' => $companyAction->getId(),
            'message_id' => $message->getId(),
            'slug' => ConfigurationEnum::ID_VERIFICATION->value,
            'entity_uuid' => Str::uuid()->toString(),
            'pipelines_stages_id' => $stage->getId(),
        ]);

        return $engagement;
    }

    protected function processDriverLicenseImages(Message $message, array $driverLicenseImages, bool $isIdValid, bool $isExpired = false): void
    {
        // Process back image
        if (isset($driverLicenseImages['back'])) {
            $backFile = $this->createFileFromBase64($driverLicenseImages['back'], 'drivers_license_back.jpg');
            $message->addFile($backFile, 'drivers_license_back');

            // Set verification metadata
            $backFile->set('id_verify', (int) $isIdValid);
            $backFile->set('id_expired', (int) $isExpired);
            $backFile->set('id_verification_msg', $this->getVerificationMessage($message, $isIdValid, $isExpired));

            if ($this->idVerificationReport) {
                $backFile->set('id_verification_status', $this->idVerificationReport['status'] ?? 'unknown');
            }
        }

        // Process front image
        if (isset($driverLicenseImages['front'])) {
            $frontFile = $this->createFileFromBase64($driverLicenseImages['front'], 'drivers_license_front.jpg');
            $message->addFile($frontFile, 'drivers_license_front');

            // Set verification metadata
            $frontFile->set('id_verify', (int) $isIdValid);
            $frontFile->set('id_expired', (int) $isExpired);
            $frontFile->set('id_verification_msg', $this->getVerificationMessage($message, $isIdValid, $isExpired));

            if ($this->idVerificationReport) {
                $frontFile->set('id_verification_status', $this->idVerificationReport['status'] ?? 'unknown');
            }
        }
    }

    protected function createFileFromBase64(string $base64Data, string $fileName = 'driver_license_image.jpg'): ModelsFilesystem
    {
        $filesystemService = new FilesystemServices($this->app, $this->company);

        return $filesystemService->createFileSystemFromBase64(
            $base64Data,
            $fileName,
            $this->user
        );
    }

    protected function parseAddress(string $fullAddress): ?array
    {
        // Remove extra spaces and normalize, but preserve newlines for initial parsing
        $address = trim($fullAddress);

        // Handle newline-separated addresses (street\ncity, state zip)
        if (strpos($address, "\n") !== false) {
            $parts = explode("\n", $address);
            if (count($parts) >= 2) {
                $streetAddress = trim($parts[0]);
                $cityStateZip = trim($parts[1]);

                // Parse the city, state, zip part
                $pattern = '/^(.+),\s*([A-Z]{2})\s+(\d{5}(?:-\d{4})?)$/i';
                if (preg_match($pattern, $cityStateZip, $matches)) {
                    return [
                        'address' => $streetAddress,
                        'city' => trim($matches[1]),
                        'state' => strtoupper(trim($matches[2])),
                        'zipcode' => trim($matches[3]),
                    ];
                }

                // Alternative pattern without comma: City State ZIP
                $pattern2 = '/^(.+)\s+([A-Z]{2})\s+(\d{5}(?:-\d{4})?)$/i';
                if (preg_match($pattern2, $cityStateZip, $matches)) {
                    return [
                        'address' => $streetAddress,
                        'city' => trim($matches[1]),
                        'state' => strtoupper(trim($matches[2])),
                        'zipcode' => trim($matches[3]),
                    ];
                }
            }
        }

        // Normalize single-line addresses (remove extra spaces)
        $address = trim(preg_replace('/\s+/', ' ', $address));

        // Pattern to match: Street Address, City, State ZIP
        // This regex handles various formats of US addresses
        $pattern = '/^(.+),\s*([^,]+),\s*([A-Z]{2})\s+(\d{5}(?:-\d{4})?)$/i';

        if (preg_match($pattern, $address, $matches)) {
            return [
                'address' => trim($matches[1]),
                'city' => trim($matches[2]),
                'state' => strtoupper(trim($matches[3])),
                'zipcode' => trim($matches[4]),
            ];
        }

        // Alternative pattern for addresses without commas: Street Address City State ZIP
        $pattern2 = '/^(.+)\s+([A-Z]{2})\s+(\d{5}(?:-\d{4})?)$/i';

        if (preg_match($pattern2, $address, $matches)) {
            $streetAndCity = trim($matches[1]);
            $state = strtoupper(trim($matches[2]));
            $zipcode = trim($matches[3]);

            // Try to separate street from city (assuming city is the last 1-3 words before state)
            $words = explode(' ', $streetAndCity);
            if (count($words) >= 2) {
                $city = array_pop($words);
                if (count($words) >= 2 && strlen($city) < 4) {
                    // If city seems too short, include one more word
                    $city = array_pop($words) . ' ' . $city;
                }
                $street = implode(' ', $words);

                return [
                    'address' => $street,
                    'city' => $city,
                    'state' => $state,
                    'zipcode' => $zipcode,
                ];
            }
        }

        return null;
    }

    protected function validateExpirationDate(
        Lead $lead,
        People $people,
        ?array $driverLicenseData,
        ?array $idVerificationData,
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
        $currentScanOption = $lead->company->get('id_verification') ?? 'intelicheck';
        $isIdValid = (bool) ($idVerificationData[$currentScanOption] ?? false);

        // Update verification data with expiration status
        if ($idVerificationData && ! isset($idVerificationData['expired'])) {
            $idVerificationData['expired'] = $isExpired;
            $people->set('id_verification', $idVerificationData);

            if ($people->id === $lead->people->id) {
                $lead->set('id_verification', $idVerificationData);
            }
        }

        // Send notification about verification status
        $this->sendVerificationNotification($lead, $people, $isIdValid, $isExpired, $participantName);

        return $isExpired;
    }

    protected function sendVerificationNotification(
        Lead $lead,
        People $people,
        bool $isIdValid,
        bool $isExpired,
        ?string $participantName = null
    ): void {
        $name = $participantName ?? $people->name;
        //$message = $this->getVerificationMessage($people, $isIdValid, $isExpired);

        if (empty($this->intellicheckResponse)) {
            return;
        }

        $usersToNotify = UsersRepository::findUsersByArray($lead->company->get('company_manager'), $lead->app);
        $notification = new Blank(
            'id-verification-report',
            [
                'message' => $this->intellicheckResponse['message'],
                'status' => $this->intellicheckResponse['status'],
                'flags' => $this->intellicheckResponse['flags'],
                'failures' => $this->intellicheckResponse['failures'],
                'results' => $this->intellicheckResponse['results'],
                'isShowRoom' => true,
                'verificationData' => $this->intellicheckResponse,
            ],
            ['mail'],
            $lead,
        );

        $notification->setSubject($name . ' - ID Verification Report');
        Notification::send($usersToNotify, $notification);
    }

    protected function getVerificationMessage(Model $entity, bool $isIdValid, bool $isExpired): string
    {
        $name = '';

        if ($entity instanceof People) {
            $name = $entity->name;
        } elseif ($entity instanceof Message) {
            $entityModel = $entity->entity();
            $name = $entityModel instanceof Lead ? $entityModel->people->name : '';
        }

        if (! empty($this->idVerificationReport)) {
            return $this->idVerificationReport['message'] ?? $this->getDefaultVerificationMessage($name, $isIdValid, $isExpired);
        }

        return $this->getDefaultVerificationMessage($name, $isIdValid, $isExpired);
    }

    protected function getDefaultVerificationMessage(string $name, bool $isIdValid, bool $isExpired): string
    {
        if ($isIdValid && ! $isExpired) {
            return "{$name} passed the ID Verification.";
        } elseif ($isIdValid && $isExpired) {
            return "{$name} passed the ID Verification but the ID has expired.";
        } else {
            return "{$name} didn't pass the ID Verification due to ID check fail. Proceed with caution.";
        }
    }

    protected function cleanupTemporaryData(Lead $lead): void
    {
        $lead->del('driver_license_images');
        $lead->del('participants');
        $lead->del('driver_license_processed');
        $lead->del('intellicheckResponse');

        // Note: Keeping 'get_docs_drivers_license' and 'id_verification' for future reference
    }

    protected function isValidDate(string $date): bool
    {
        $dateTime = Carbon::createFromFormat('Y-m-d', $date);

        return $dateTime && $dateTime->format('Y-m-d') === $date;
    }
}
