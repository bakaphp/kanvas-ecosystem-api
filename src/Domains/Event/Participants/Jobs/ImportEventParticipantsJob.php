<?php

declare(strict_types=1);

namespace Kanvas\Event\Participants\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Event\Events\Actions\SendEventEmailsAction;
use Kanvas\Event\Events\Enums\EmailTemplateEnum;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Participants\Actions\CreateParticipantAction;
use Kanvas\Event\Participants\Models\Participant;
use Kanvas\Event\Participants\Models\ParticipantPass;
use Kanvas\Event\Participants\Models\ParticipantPassMotive;
use Kanvas\Event\Passes\Actions\CreatePassAction;
use Kanvas\Event\Passes\Enums\PassFormatEnum;
use Kanvas\Event\Passes\Services\PassMotiveService;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Users\Models\Users;
use Throwable;

class ImportEventParticipantsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    private const int LOCK_TTL_SECONDS = 60;
    private const int LOCK_WAIT_SECONDS = 10;

    public function __construct(
        public readonly Apps $app,
        public readonly Users $user,
        public readonly EventVersion $eventVersion,
        public readonly array $rows,
        public readonly ?string $emailTemplate = null,
        public readonly int $codeLength = 8,
        public readonly bool $reissue = false,
    ) {
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        $company = $this->eventVersion->company;
        $branch = $company->defaultBranch ?? $company->branch;
        $motive = PassMotiveService::getMotive(
            company: $company,
            app: $this->app,
            userId: $this->user->getId()
        );

        foreach ($this->rows as $row) {
            try {
                $this->importRowLocked($row, $branch, $motive);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    private function importRowLocked(array $row, CompaniesBranches $branch, ParticipantPassMotive $motive): void
    {
        $lock = Cache::lock(
            'event-import:' . $this->eventVersion->getId() . ':' . strtolower($row['email']),
            self::LOCK_TTL_SECONDS
        );

        try {
            $lock->block(self::LOCK_WAIT_SECONDS);
        } catch (LockTimeoutException $exception) {
            report($exception);

            return;
        }

        try {
            $this->importRow($row, $branch, $motive);
        } finally {
            $lock->release();
        }
    }

    private function importRow(array $row, CompaniesBranches $branch, ParticipantPassMotive $motive): void
    {
        $participant = new CreateParticipantAction(
            $this->app,
            $branch,
            $this->user,
            [
                'firstname' => $row['firstname'] ?? null,
                'lastname' => $row['lastname'] ?? null,
                'contacts' => [
                    [
                        'contacts_types_id' => ContactTypeEnum::EMAIL->value,
                        'value' => $row['email'],
                        'weight' => 1,
                    ],
                ],
            ],
            $this->eventVersion
        )->execute();

        if ($participant === null) {
            return;
        }

        $activePass = $this->activePass($participant);

        if ($activePass !== null) {
            if (! $this->reissue || $activePass->redeemed_at !== null) {
                return;
            }

            $activePass->update(['revoked_at' => Carbon::now()]);
        }

        [, $plainCode] = new CreatePassAction(
            $this->eventVersion->event,
            $this->eventVersion,
            $motive,
            $participant->getId(),
            format: PassFormatEnum::QR_CODE,
            codeLength: $this->codeLength
        )->execute();

        new SendEventEmailsAction(
            $this->eventVersion,
            $this->emailTemplate ?? EmailTemplateEnum::PARTICIPANT_NOTIFICATION->value,
            ['code' => $plainCode]
        )->execute($participant);
    }

    private function activePass(Participant $participant): ?ParticipantPass
    {
        return ParticipantPass::query()
            ->where('event_version_id', $this->eventVersion->getId())
            ->where('participant_id', $participant->getId())
            ->where('apps_id', $this->eventVersion->apps_id)
            ->where('companies_id', $this->eventVersion->companies_id)
            ->whereNull('revoked_at')
            ->notDeleted()
            ->first();
    }
}
