<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Services;

use Baka\Support\Str;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Kanvas\ActionEngine\Actions\Models\Action;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Tasks\Actions\ChangeTaskEngagementItemStatusAction;
use Kanvas\ActionEngine\Tasks\Enums\TaskStatusEnum;
use Kanvas\ActionEngine\Tasks\Models\TaskListItem;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\SalesAssist\Enums\ConfigurationEnum;
use Kanvas\Filesystem\Models\Filesystem as ModelsFilesystem;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Guild\Customers\Actions\UpdatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\Address as DataTransferObjectAddress;
use Kanvas\Guild\Customers\DataTransferObject\Contact as DataTransferObjectContact;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleDataInput;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\DataCollection;

class DriverLicenseVerificationService
{
    protected ?array $idVerificationReport = null;

    public function __construct(
        protected Apps $app,
        protected Companies $company,
        protected Users $user,
    ) {
    }

    public function setIdVerificationReport(?array $report): self
    {
        $this->idVerificationReport = $report;

        return $this;
    }

    public static function parseAddress(string $fullAddress): ?array
    {
        $address = trim($fullAddress);

        if (strpos($address, "\n") !== false) {
            $parts = explode("\n", $address);
            if (count($parts) >= 2) {
                $streetAddress = trim($parts[0]);
                $cityStateZip = trim($parts[1]);

                $pattern = '/^(.+),\s*([A-Z]{2})\s+(\d{5}(?:-\d{4})?)$/i';
                if (preg_match($pattern, $cityStateZip, $matches)) {
                    return [
                        'address' => $streetAddress,
                        'city' => trim($matches[1]),
                        'state' => strtoupper(trim($matches[2])),
                        'zipcode' => trim($matches[3]),
                    ];
                }

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

        $address = trim(preg_replace('/\s+/', ' ', $address));

        $pattern = '/^(.+),\s*([^,]+),\s*([A-Z]{2})\s+(\d{5}(?:-\d{4})?)$/i';
        if (preg_match($pattern, $address, $matches)) {
            return [
                'address' => trim($matches[1]),
                'city' => trim($matches[2]),
                'state' => strtoupper(trim($matches[3])),
                'zipcode' => trim($matches[4]),
            ];
        }

        $pattern2 = '/^(.+)\s+([A-Z]{2})\s+(\d{5}(?:-\d{4})?)$/i';
        if (preg_match($pattern2, $address, $matches)) {
            $streetAndCity = trim($matches[1]);
            $state = strtoupper(trim($matches[2]));
            $zipcode = trim($matches[3]);

            $words = explode(' ', $streetAndCity);
            if (count($words) >= 2) {
                $city = array_pop($words);
                if (count($words) >= 2 && strlen($city) < 4) {
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

    public static function isValidDate(string $date): bool
    {
        $dateTime = Carbon::createFromFormat('Y-m-d', $date);

        return $dateTime && $dateTime->format('Y-m-d') === $date;
    }

    public static function getDefaultVerificationMessage(string $name, bool $isIdValid, bool $isExpired): string
    {
        if ($isIdValid && ! $isExpired) {
            return "{$name} passed the ID Verification.";
        }

        if ($isIdValid && $isExpired) {
            return "{$name} passed the ID Verification but the ID has expired.";
        }

        return "{$name} didn't pass the ID Verification due to ID check fail. Proceed with caution.";
    }

    public function getVerificationMessage(Model $entity, bool $isIdValid, bool $isExpired): string
    {
        $name = '';

        if ($entity instanceof People) {
            $name = $entity->name;
        } elseif ($entity instanceof Message) {
            $entityModel = $entity->entity();
            $name = $entityModel instanceof Lead ? $entityModel->people->name : '';
        }

        if ($this->idVerificationReport !== null && $this->idVerificationReport !== []) {
            return $this->idVerificationReport['message'] ?? self::getDefaultVerificationMessage($name, $isIdValid, $isExpired);
        }

        return self::getDefaultVerificationMessage($name, $isIdValid, $isExpired);
    }

    public function processDriverLicenseImages(
        Message $message,
        array $driverLicenseImages,
        bool $isIdValid,
        bool $isExpired = false
    ): void {
        if (isset($driverLicenseImages['back'])) {
            $backFile = $this->createFileFromBase64($driverLicenseImages['back'], 'drivers_license_back.jpg');
            $message->addFile($backFile, 'drivers_license_back');

            $backFile->set('id_verify', (int) $isIdValid);
            $backFile->set('id_expired', (int) $isExpired);
            $backFile->set('id_verification_msg', $this->getVerificationMessage($message, $isIdValid, $isExpired));

            if ($this->idVerificationReport !== null) {
                $backFile->set('id_verification_status', $this->idVerificationReport['status'] ?? 'unknown');
            }
        }

        if (isset($driverLicenseImages['front'])) {
            $frontFile = $this->createFileFromBase64($driverLicenseImages['front'], 'drivers_license_front.jpg');
            $message->addFile($frontFile, 'drivers_license_front');

            $frontFile->set('id_verify', (int) $isIdValid);
            $frontFile->set('id_expired', (int) $isExpired);
            $frontFile->set('id_verification_msg', $this->getVerificationMessage($message, $isIdValid, $isExpired));

            if ($this->idVerificationReport !== null) {
                $frontFile->set('id_verification_status', $this->idVerificationReport['status'] ?? 'unknown');
            }
        }
    }

    public function createFileFromBase64(
        string $base64Data,
        string $fileName = 'driver_license_image.jpg'
    ): ModelsFilesystem {
        return new FilesystemServices($this->app, $this->company)->createFileSystemFromBase64(
            $base64Data,
            $fileName,
            $this->user,
        );
    }

    public function updatePeopleFromDriverLicense(People $people, array $driverLicenseData): PeopleDataInput
    {
        $addressComponents = isset($driverLicenseData['address']) ?
            self::parseAddress($driverLicenseData['address']) : null;

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

        $dob = null;
        if (isset($driverLicenseData['birthday'])) {
            $birthday = $driverLicenseData['birthday'];
            $birthDateString = sprintf(
                '%04d-%02d-%02d',
                $birthday['year'],
                $birthday['month'],
                $birthday['day']
            );
            if (self::isValidDate($birthDateString)) {
                $dob = Carbon::createFromFormat('Y-m-d', $birthDateString);
            }
        }

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
            license_number: $driverLicenseData['license'] ?? null,
            custom_fields: [],
            tags: []
        );

        $people = new UpdatePeopleAction($people, $peopleData)->execute();

        if (! empty($driverLicenseData['license'])) {
            $people->set('drivers_license_number', $driverLicenseData['license']);
        }

        return $peopleData;
    }

    public function createEngagement(Lead $lead, People $people): Engagement
    {
        $action = Action::getBySlug(ConfigurationEnum::ID_VERIFICATION->value, $lead->company);
        $companyAction = CompanyAction::getByAction($action, $lead->company, $this->app);

        $pipeline = $companyAction->pipeline;
        $stage = $pipeline->stages()->where('slug', 'submitted')->firstOrFail();

        $messageType = MessageType::fromApp($this->app)
            ->where('verb', ConfigurationEnum::ID_VERIFICATION->value)
            ->first();

        if (! $messageType) {
            $messageType = new CreateMessageTypeAction(
                new MessageTypeInput(
                    $this->app->getId(),
                    1,
                    'ID Verification',
                    ConfigurationEnum::ID_VERIFICATION->value,
                )
            )->execute();
        }

        $messageInput = new MessageInput(
            app: $this->app,
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

        $engagement = Engagement::firstOrCreate([
            'companies_id' => $lead->company->getId(),
            'apps_id' => $this->app->getId(),
            'users_id' => $lead->user->getId(),
            'leads_id' => $lead->getId(),
            'people_id' => $people->getId(),
            'companies_actions_id' => $companyAction->getId(),
            'message_id' => $message->getId(),
            'slug' => ConfigurationEnum::ID_VERIFICATION->value,
            'entity_uuid' => Str::uuid()->toString(),
            'pipelines_stages_id' => $stage->getId(),
        ]);

        $companyTaskList = TaskListItem::query()
            ->where('companies_action_id', $companyAction->getId())
            ->where('is_deleted', 0);

        if ($companyTaskList->exists()) {
            foreach ($companyTaskList->get() as $taskItem) {
                new ChangeTaskEngagementItemStatusAction(
                    taskListItem: $taskItem,
                    lead: $lead,
                    status: TaskStatusEnum::COMPLETED->value,
                    user: $this->user,
                    app: $this->app,
                    company: $this->company,
                    message: $message
                )->execute();
            }
        }

        return $engagement;
    }
}
