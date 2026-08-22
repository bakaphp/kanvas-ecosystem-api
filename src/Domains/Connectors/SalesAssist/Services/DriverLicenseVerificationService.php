<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Services;

use Baka\Support\AddressParser;
use Baka\Support\Str;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Kanvas\ActionEngine\Actions\Models\Action;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Engagements\Repositories\EngagementRepository;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\ActionEngine\Tasks\Actions\ChangeTaskEngagementItemStatusAction;
use Kanvas\ActionEngine\Tasks\Enums\TaskStatusEnum;
use Kanvas\ActionEngine\Tasks\Models\TaskListItem;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\SalesAssist\Enums\ConfigurationEnum;
use Kanvas\Filesystem\Models\Filesystem as ModelsFilesystem;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Filesystem\Services\PdfService;
use Kanvas\Guild\Customers\Actions\UpdatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\Address as DataTransferObjectAddress;
use Kanvas\Guild\Customers\DataTransferObject\Contact as DataTransferObjectContact;
use Kanvas\Guild\Customers\DataTransferObject\DriverLicense;
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
use Throwable;

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
        return AddressParser::parse($fullAddress);
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

    /** `$runWorkflow: false` for backfills — old scans must not fire lead automations. */
    public function updatePeopleFromDriverLicense(
        People $people,
        array $driverLicenseData,
        bool $runWorkflow = true
    ): PeopleDataInput {
        $license = DriverLicense::fromScan($driverLicenseData);

        $addressComponents = $license?->address !== null ? self::parseAddress($license->address) : null;

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

        $peopleData = new PeopleDataInput(
            app: $this->app,
            branch: $people->company->defaultBranch,
            user: $this->user,
            firstname: $license?->firstname ?? $people->firstname,
            lastname: $license?->lastname ?? $people->lastname,
            middlename: $license?->middlename ?? $people->middlename,
            dob: $license?->dob,
            contacts: DataTransferObjectContact::collect([], DataCollection::class),
            address: DataTransferObjectAddress::collect($addressArray, DataCollection::class),
            id: $people->id,
            license_number: $license?->number,
            license_expiration_date: $license?->expirationDate,
            license_state: $license?->state,
            custom_fields: [],
            tags: []
        );

        $updatePeople = new UpdatePeopleAction($people, $peopleData);
        $updatePeople->runWorkflow = $runWorkflow;
        $updatePeople->execute();

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

    public function generateIdVerificationPdf(
        Lead $lead,
        People $people,
        array $idVerificationReport,
        array $intellicheckResponse,
        ?Message $targetMessage = null
    ): ?ModelsFilesystem {
        try {
            $pdfReport = PdfService::generatePdfFromTemplate(
                $lead->app,
                $lead->user,
                'id-verification-report',
                $lead,
                [
                    'message' => $idVerificationReport['message'],
                    'status' => $idVerificationReport['status'],
                    'flags' => $idVerificationReport['flags'],
                    'failures' => $idVerificationReport['failures'],
                    'results' => $idVerificationReport['results'],
                    'isShowRoom' => true,
                    'verificationData' => $intellicheckResponse,
                    'people' => $people,
                ]
            );

            // Attach to the passed engagement message (a participant's own, or the
            // main lead's); fall back to the lead's submitted ID-verification
            // engagement, then to the lead itself.
            $message = $targetMessage ?? EngagementRepository::findEngagementForLead(
                $lead,
                ConfigurationEnum::ID_VERIFICATION->value,
                ActionStatusEnum::SUBMITTED->value,
            )?->message;

            if ($message instanceof Message) {
                $message->addFile($pdfReport, 'id-verification');
            } else {
                $lead->addFile($pdfReport, 'id-verification');
            }

            return $pdfReport;
        } catch (Throwable $e) {
            report($e);
        }

        return null;
    }
}
