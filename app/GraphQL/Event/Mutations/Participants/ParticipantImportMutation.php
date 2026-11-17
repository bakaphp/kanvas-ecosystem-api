<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Mutations\Participants;

use App\GraphQL\Concerns\ResolvesActingContext;
use Baka\Support\Str;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Participants\Jobs\ImportEventParticipantsJob;
use Kanvas\Exceptions\ValidationException;

class ParticipantImportMutation
{
    use ResolvesActingContext;

    private const int MAX_ROWS = 1000;
    private const int ROWS_PER_JOB = 100;
    private const int MIN_CODE_LENGTH = 4;
    private const int MAX_CODE_LENGTH = 32;

    public function import(mixed $root, array $request): array
    {
        $ctx = $this->actingContext();
        $input = $request['input'];

        $eventVersion = EventVersion::getByIdFromCompanyApp(
            (int) $input['event_version_id'],
            $ctx->company,
            $ctx->app
        );

        $codeLength = (int) ($input['code_length'] ?? 8);
        if ($codeLength < self::MIN_CODE_LENGTH || $codeLength > self::MAX_CODE_LENGTH) {
            throw new ValidationException(
                'code_length must be between ' . self::MIN_CODE_LENGTH . ' and ' . self::MAX_CODE_LENGTH . '.'
            );
        }

        $rows = $this->normalizeRows($input['rows']);

        foreach (array_chunk($rows, self::ROWS_PER_JOB) as $chunk) {
            ImportEventParticipantsJob::dispatch(
                $ctx->app,
                $ctx->user,
                $eventVersion,
                $chunk,
                Str::trimToNull($input['email_template'] ?? null),
                $codeLength,
                (bool) ($input['reissue'] ?? false)
            );
        }

        return [
            'queued' => count($rows),
            'message' => 'Participant import queued.',
        ];
    }

    private function normalizeRows(array $rows): array
    {
        if ($rows === [] || count($rows) > self::MAX_ROWS) {
            throw new ValidationException('rows must contain between 1 and ' . self::MAX_ROWS . ' entries.');
        }

        $normalized = [];
        $invalid = [];

        foreach ($rows as $index => $row) {
            $email = strtolower(trim((string) $row['email']));

            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $invalid[] = $index + 1;

                continue;
            }

            $normalized[$email] ??= [
                'email' => $email,
                'firstname' => Str::trimToNull($row['firstname'] ?? null),
                'lastname' => Str::trimToNull($row['lastname'] ?? null),
            ];
        }

        if ($invalid !== []) {
            throw new ValidationException('Invalid email on row(s): ' . implode(', ', $invalid) . '.');
        }

        return array_values($normalized);
    }
}
