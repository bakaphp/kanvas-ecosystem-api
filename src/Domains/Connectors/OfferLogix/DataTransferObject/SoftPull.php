<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OfferLogix\DataTransferObject;

use DateTime;
use Kanvas\Guild\Customers\Models\People;
use Spatie\LaravelData\Data;

class SoftPull extends Data
{
    public function __construct(
        public readonly string $first_name,
        public readonly string $last_name,
        public readonly string $last_4_digits_of_ssn,
        public readonly string $city,
        public readonly string $state,
        public readonly ?string $middle_name,
        public readonly ?string $dob,
        public readonly ?string $mobile,
        public readonly ?string $email
    ) {
    }

    public static function fromMultiple(People $people, array $data): self
    {
        return new self(
            $data['first_name'] ?? $people->firstname,
            $data['last_name'] ?? $people->lastname,
            $data['last_4_digits_of_ssn'],
            $data['city'],
            $data['state'],
            $data['middle_name'] ?? $people->middlename,
            ! empty($data['dob']) ? DateTime::createFromFormat('m/d/Y', $data['dob'])->format('Y-m-d') : $people->dob,
            $data['mobile'] ?? null,
            $data['email'] ?? null,
        );
    }

    public static function fromMessage(People $people, array $message): self
    {
        foreach ($message['data'] as $item) {
            // Convert label to variable name (replace space with underscore)
            $key = str_replace(' ', '_', strtolower($item['label']));
            // Assign value to the variable
            $data[$key] = $item['value'];
        }

        return self::fromMultiple($people, $data);
    }

    public function getName(): string
    {
        return $this->first_name . ' ' . $this->last_name;
    }
}
