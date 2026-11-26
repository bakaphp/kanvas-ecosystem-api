<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Meetings\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\HumanResources\Meetings\Models\Meeting as MeetingModel;
use Spatie\LaravelData\Data;

class Meeting extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly UserInterface $user,
        public readonly string $title,
        public readonly ?string $uuid = null,
        public readonly ?Carbon $startedAt = null,
        public readonly ?Carbon $endedAt = null,
        public readonly ?People $people = null,
    ) {
        if ($startedAt !== null && $endedAt !== null && $endedAt->lt($startedAt)) {
            throw new ValidationException('A meeting cannot end before it starts');
        }
    }

    public static function fromMultiple(
        AppInterface $app,
        UserInterface $user,
        CompanyInterface $company,
        array $data,
    ): self {
        return new self(
            app: $app,
            company: $company,
            user: $user,
            title: (string) $data['title'],
            uuid: $data['uuid'] ?? null,
            startedAt: self::parseDate($data['started_at'] ?? null),
            endedAt: self::parseDate($data['ended_at'] ?? null),
            people: self::findPeople($data['people_id'] ?? null, $company, $app),
        );
    }

    public static function forUpdate(MeetingModel $meeting, array $data): self
    {
        return new self(
            app: $meeting->app,
            company: $meeting->company,
            user: $meeting->user,
            title: (string) ($data['title'] ?? $meeting->title),
            uuid: $meeting->uuid,
            startedAt: array_key_exists('started_at', $data)
                ? self::parseDate($data['started_at'])
                : $meeting->started_at,
            endedAt: array_key_exists('ended_at', $data)
                ? self::parseDate($data['ended_at'])
                : $meeting->ended_at,
            people: array_key_exists('people_id', $data)
                ? self::findPeople($data['people_id'], $meeting->company, $meeting->app)
                : $meeting->people,
        );
    }

    /**
     * Scoped to the meeting's own company and app: an id from another tenant resolves to nothing.
     */
    protected static function findPeople(mixed $id, CompanyInterface $company, AppInterface $app): ?People
    {
        if ($id === null) {
            return null;
        }

        try {
            return People::getByIdFromCompanyApp((int) $id, $company, $app);
        } catch (ModelNotFoundException) {
            throw new ValidationException('No person found with ID ' . $id);
        }
    }

    protected static function parseDate(mixed $value): ?Carbon
    {
        return $value !== null ? Carbon::parse($value) : null;
    }
}
