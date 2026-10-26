<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Kanvas\Connectors\Zoho\Actions\Concerns\SyncsZohoPerson;
use Kanvas\Connectors\Zoho\Actions\Concerns\TracksZohoSyncedFiles;
use Kanvas\Connectors\Zoho\Client;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Connectors\Zoho\Services\ZohoFieldMapService;
use Kanvas\Filesystem\Actions\AttachFilesystemAction;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleData;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Deals\Actions\CreateDealAction;
use Kanvas\Guild\Deals\Actions\UpdateDealAction;
use Kanvas\Guild\Deals\DataTransferObject\Deal as DealData;
use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Spatie\LaravelData\DataCollection;
use Throwable;
use Webleit\ZohoCrmApi\ZohoCrm;

/**
 * Zoho → Kanvas for one deal. The webhook, the pull button and the bulk download all land here so
 * the three paths cannot drift on how a Zoho deal becomes a Kanvas one.
 */
class PullZohoDealAction
{
    use SyncsZohoPerson;
    use TracksZohoSyncedFiles;

    /**
     * Where the Contacts module keeps each person field; the Leads names in PERSON_FIELDS differ.
     */
    protected const array CONTACT_FIELDS = [
        'first_name' => 'First_Name',
        'last_name' => 'Last_Name',
        'email' => 'Email',
        'phone' => 'Phone',
        'street' => 'Mailing_Street',
        'city' => 'Mailing_City',
        'state' => 'Mailing_State',
        'zip' => 'Mailing_Zip',
        'Date_of_Birth' => 'Date_of_Birth',
    ];

    protected ?ZohoCrm $zohoCrm = null;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected UserInterface $user,
        protected string $zohoDealId,
    ) {
    }

    /**
     * Null when Zoho has no deal with that id. Check `wasRecentlyCreated` on the result to tell a
     * create from an update.
     */
    public function execute(?array $zohoDealInfo = null): ?Deal
    {
        $zohoDealInfo ??= $this->fetchDeal();

        if ($zohoDealInfo === []) {
            return null;
        }

        $lead = $this->findRelatedLead($zohoDealInfo);
        $people = $this->resolvePeople($zohoDealInfo, $lead);

        // The id was just written on `ecosystem` while the join reads from `crm`; the two-query form
        // sees it regardless of which connection opened its snapshot first.
        /** @var Deal|null $existing */
        $existing = Deal::getByCustomFieldTransactionSafe(
            CustomFieldEnum::ZOHO_DEAL_ID->value,
            $this->zohoDealId,
            $this->company
        ) ?? Deal::getByCustomField(
            CustomFieldEnum::ZOHO_DEAL_ID->value,
            $this->zohoDealId,
            $this->company
        );

        $dealData = new DealData(
            app: $this->app,
            company: $this->company,
            user: $this->user,
            title: (string) ($zohoDealInfo['Deal_Name'] ?? $zohoDealInfo['Name'] ?? ('Zoho Deal ' . $this->zohoDealId)),
            description: isset($zohoDealInfo['Description']) ? (string) $zohoDealInfo['Description'] : null,
            lead: $lead,
            people: $people,
        );

        // The push activity listens on created/updated; it skips a change that carries zoho_payload,
        // so the workflow is fired by hand below instead of by the action.
        $deal = $existing !== null
            ? new UpdateDealAction($existing, $dealData, runWorkflow: false)->execute()
            : new CreateDealAction($dealData, runWorkflow: false)->execute();

        $deal->set(CustomFieldEnum::ZOHO_DEAL_ID->value, $this->zohoDealId);
        $deal->set(CustomFieldEnum::ZOHO_DEAL_RAW->value, $zohoDealInfo);

        $this->mapCustomFields($deal, $zohoDealInfo);
        $this->syncAddressCustomFields($deal, $people?->getDefaultAddress());
        $this->pullAttachments($deal);

        $deal->fireWorkflow(
            $existing !== null ? WorkflowEnum::UPDATED->value : WorkflowEnum::CREATED->value,
            true,
            ['zoho_payload' => $zohoDealInfo]
        );

        return $deal;
    }

    protected function fetchDeal(): array
    {
        return $this->zohoClient()->deals->get($this->zohoDealId)->getData();
    }

    protected function zohoClient(): ZohoCrm
    {
        return $this->zohoCrm ??= Client::getInstance($this->app, $this->company);
    }

    protected function findRelatedLead(array $zohoDealInfo): ?Lead
    {
        $raw = $zohoDealInfo['ZCRM_Lead_ID'] ?? null;
        $zohoLeadId = is_scalar($raw) ? Str::trimToNull((string) $raw) : null;

        if ($zohoLeadId === null) {
            return null;
        }

        /** @var Lead|null $lead */
        $lead = Lead::getByCustomFieldTransactionSafe(
            CustomFieldEnum::ZOHO_LEAD_ID->value,
            $zohoLeadId,
            $this->company
        ) ?? Lead::getByCustomField(
            CustomFieldEnum::ZOHO_LEAD_ID->value,
            $zohoLeadId,
            $this->company
        );

        return $lead;
    }

    /**
     * The deal is the later record, so what it carries about the person overwrites the lead's
     * copy; a deal that names nobody leaves the person as the lead left them.
     */
    protected function resolvePeople(array $zohoDealInfo, ?Lead $lead): ?People
    {
        $fieldMapping = ZohoFieldMapService::dealMapFor($this->company);
        $zohoDealInfo = $this->withLinkedContact($zohoDealInfo, $fieldMapping);
        $people = $this->findPeople($zohoDealInfo, $lead, $fieldMapping);

        if ($people !== null) {
            $this->syncPerson($people, $zohoDealInfo, $fieldMapping);

            return $people;
        }

        $firstName = $this->personFirstNameForCreate($zohoDealInfo, $fieldMapping);

        if ($firstName === null) {
            return null;
        }

        $address = $this->personAddress($zohoDealInfo, $fieldMapping);
        $createPeople = new CreatePeopleAction(
            new PeopleData(
                app: $this->app,
                branch: $lead?->branch ?? $this->company->defaultBranch,
                user: $this->user,
                firstname: $firstName,
                contacts: $this->personContacts($zohoDealInfo, $fieldMapping),
                address: Address::collect($address ? [$address] : [], DataCollection::class),
                lastname: $this->personLastName($zohoDealInfo, $fieldMapping),
                dob: $this->personDateOfBirth($zohoDealInfo, $fieldMapping),
            )
        );
        $createPeople->runWorkflow = false;

        return $createPeople->execute();
    }

    /**
     * A stock deal carries its person as `Contact_Name: {name, id}` and nothing else; the email,
     * phone and mailing address live on the Contact record, laid here under the names the deal
     * map reads. The Contact is the person, so it wins: a deal's own City/State/Zip_Code is the
     * business address on the layouts seen so far, not the person's.
     */
    protected function withLinkedContact(array $zohoDealInfo, array $fieldMapping): array
    {
        $contactId = is_array($zohoDealInfo['Contact_Name'] ?? null)
            ? ($zohoDealInfo['Contact_Name']['id'] ?? null)
            : null;

        if (! is_scalar($contactId) || (string) $contactId === '') {
            return $zohoDealInfo;
        }

        $contact = $this->fetchContact((string) $contactId);

        if ($contact === []) {
            return $zohoDealInfo;
        }

        $contact['Phone'] = $contact['Phone'] ?? $contact['Mobile'] ?? null;
        $fromContact = [];

        foreach (self::CONTACT_FIELDS as $personKey => $contactField) {
            $value = $contact[$contactField] ?? null;

            if (is_scalar($value) && Str::trimToNull((string) $value) !== null) {
                $fromContact[ZohoFieldMapService::personFieldName($fieldMapping, $personKey)] = $value;
            }
        }

        return $fromContact + $zohoDealInfo;
    }

    protected function fetchContact(string $contactId): array
    {
        try {
            return $this->zohoClient()->contacts->get($contactId)->getData();
        } catch (Throwable) {
            return [];
        }
    }

    protected function findPeople(array $zohoDealInfo, ?Lead $lead, array $fieldMapping): ?People
    {
        if ($lead !== null && $lead->people !== null) {
            return $lead->people;
        }

        $email = $this->personValue($zohoDealInfo, $fieldMapping, 'email') ?? $zohoDealInfo['Primary_Email'] ?? null;

        if (! is_string($email) || $email === '') {
            return null;
        }

        /** @var People|null $people */
        $people = People::fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->whereHas(
                'contacts',
                fn (Builder $q) => $q->where('value', $email)
            )
            ->first();

        return $people;
    }

    protected function mapCustomFields(Deal $deal, array $zohoDealInfo): void
    {
        $customFields = ZohoFieldMapService::extract(
            ZohoFieldMapService::dealMapFor($this->company),
            $zohoDealInfo,
            ZohoFieldMapService::tableColumns(new Deal())
        );

        foreach ($customFields as $name => $value) {
            $deal->set($name, $value);
        }
    }

    protected function pullAttachments(Deal $deal): void
    {
        try {
            $attachmentsRaw = $this->zohoClient()->deals->getRelatedRecords($this->zohoDealId, 'Attachments');
        } catch (Throwable) {
            return;
        }

        if (! is_array($attachmentsRaw) || $attachmentsRaw === []) {
            return;
        }

        // Zoho wraps records in {"data": [...], "info": {...}} — unwrap the data list
        $attachments = isset($attachmentsRaw['data']) && is_array($attachmentsRaw['data'])
            ? $attachmentsRaw['data']
            : $attachmentsRaw;

        if ($attachments === []) {
            return;
        }

        $existingNames = [];

        foreach ($deal->getFiles() as $existing) {
            $existingNames[] = (string) ($existing->name ?? '');
        }

        $filesystemService = new FilesystemServices($this->app, $this->company);

        foreach ($attachments as $attachment) {
            if (! is_array($attachment)) {
                continue;
            }

            $attachmentId = $attachment['id'] ?? null;
            $fileName = $attachment['File_Name'] ?? $attachment['name'] ?? null;

            if (! is_string($attachmentId) || $attachmentId === '') {
                continue;
            }

            if (! is_string($fileName) || $fileName === '') {
                continue;
            }

            if (in_array($fileName, $existingNames, true)) {
                continue;
            }

            $this->downloadAndAttach(
                $deal,
                $attachmentId,
                $fileName,
                $filesystemService
            );
        }
    }

    protected function downloadAndAttach(
        Deal $deal,
        string $attachmentId,
        string $fileName,
        FilesystemServices $filesystemService
    ): void {
        $tmpPath = tempnam(sys_get_temp_dir(), 'zoho-attach-');

        if ($tmpPath === false) {
            return;
        }

        $resource = fopen($tmpPath, 'w+');

        if ($resource === false) {
            @unlink($tmpPath);

            return;
        }

        try {
            $this->zohoClient()->deals->downloadAttachment($this->zohoDealId, $attachmentId, $resource);
        } catch (Throwable) {
            /** @psalm-suppress RedundantCondition — Zoho SDK may close the stream itself */
            if (is_resource($resource)) {
                fclose($resource);
            }
            @unlink($tmpPath);

            return;
        }
        /** @psalm-suppress RedundantCondition — Zoho SDK may close the stream itself */
        if (is_resource($resource)) {
            fclose($resource);
        }

        try {
            $mime = (string) (mime_content_type($tmpPath) ?: 'application/octet-stream');
            $uploadedFile = new UploadedFile(
                $tmpPath,
                $fileName,
                $mime,
                test: true
            );
            $filesystem = $filesystemService->upload($uploadedFile, $this->user);
            new AttachFilesystemAction($filesystem, $deal)->execute($fileName);
            $this->rememberSyncedFiles($deal, CustomFieldEnum::ZOHO_DEAL_SYNC_FILES, [$filesystem->getId()]);
        } catch (Throwable) {
        } finally {
            if (file_exists($tmpPath)) {
                @unlink($tmpPath);
            }
        }
    }
}
