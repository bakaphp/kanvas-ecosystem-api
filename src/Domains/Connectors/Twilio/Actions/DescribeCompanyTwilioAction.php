<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Actions;

use Baka\Support\Str;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Repositories\AgentsRepository;
use Throwable;
use Twilio\Exceptions\RestException;

/**
 * The company's Twilio connection as an admin needs to see it before calls or texts go out: which
 * account, whether it can send at all (status, trial, balance), and whether the default From number
 * is on it. Never throws — a missing or rejected connection is reported in `error`.
 */
class DescribeCompanyTwilioAction
{
    public function __construct(private readonly Companies $company)
    {
    }

    /**
     * @return array{
     *   connected: bool,
     *   company_name: string,
     *   account_name: string|null,
     *   account_sid_suffix: string|null,
     *   status: string|null,
     *   type: string|null,
     *   balance: float|null,
     *   currency: string|null,
     *   number_count: int,
     *   from_number: string|null,
     *   problems: list<array{key: string, severity: string, message: string}>,
     *   error: string|null
     * }
     */
    public function execute(): array
    {
        $fromNumber = Str::trimToNull((string) $this->company->get(ConfigurationEnum::TWILIO_FROM_PHONE_NUMBER->value));
        $description = [
            'connected' => false,
            'company_name' => (string) $this->company->name,
            'account_name' => null,
            'account_sid_suffix' => null,
            'status' => null,
            'type' => null,
            'balance' => null,
            'currency' => null,
            'number_count' => 0,
            'from_number' => $fromNumber,
            'problems' => [],
            'error' => null,
        ];

        $account = $this->account();

        try {
            $numbers = $account->execute();
        } catch (ValidationException) {
            return [...$description, 'error' => 'Twilio is not connected for this company.'];
        } catch (RestException $e) {
            return [...$description, 'error' => "Twilio rejected the saved credentials: {$e->getMessage()}"];
        } catch (Throwable $e) {
            return [...$description, 'error' => "Could not reach Twilio: {$e->getMessage()}"];
        }

        $details = $account->account();
        $balance = $account->balance();
        $description = [
            ...$description,
            'connected' => true,
            'account_name' => $details['friendly_name'],
            'account_sid_suffix' => $details['sid_suffix'],
            'status' => $details['status'],
            'type' => $details['type'],
            'balance' => $balance['amount'] ?? null,
            'currency' => $balance['currency'] ?? null,
            'number_count' => count($numbers),
        ];

        return [...$description, 'problems' => $this->problems($description, $numbers)];
    }

    protected function account(): ListCompanyPhoneNumbersAction
    {
        return new ListCompanyPhoneNumbersAction($this->company);
    }

    /**
     * @param array<string, mixed> $description
     * @param array<int, array{phone_number: string}> $numbers
     *
     * @return list<array{key: string, severity: string, message: string}>
     */
    private function problems(array $description, array $numbers): array
    {
        $problems = [];

        if ($description['status'] !== null && $description['status'] !== 'active') {
            $problems[] = $this->problem('status', 'error', "This Twilio account is {$description['status']}, so calls and texts fail.");
        }

        if ($description['type'] === 'Trial') {
            $problems[] = $this->problem('trial', 'warning', 'Trial account: it can only call and text verified numbers, and callers hear a trial message first.');
        }

        if ($description['balance'] !== null && $description['balance'] <= 0) {
            $amount = number_format($description['balance'], 2) . " {$description['currency']}";
            $problems[] = $this->problem('balance', 'error', "The balance is {$amount}, so Twilio refuses calls and texts until it is topped up.");
        }

        $fromNumber = $description['from_number'];
        if ($fromNumber !== null && ! $this->hasNumber($numbers, $fromNumber)) {
            $problems[] = $this->problem('from_number', 'warning', "The default From number {$fromNumber} is not on this account, so agents without their own number can't call or text from it.");
        }

        return $problems;
    }

    /**
     * @param array<int, array{phone_number: string}> $numbers
     */
    private function hasNumber(array $numbers, string $phoneNumber): bool
    {
        $wanted = AgentsRepository::normalizePhoneNumber($phoneNumber);

        foreach ($numbers as $number) {
            if (AgentsRepository::normalizePhoneNumber($number['phone_number']) === $wanted) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{key: string, severity: string, message: string}
     */
    private function problem(string $key, string $severity, string $message): array
    {
        return ['key' => $key, 'severity' => $severity, 'message' => $message];
    }
}
