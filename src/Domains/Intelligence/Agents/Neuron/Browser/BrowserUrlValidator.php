<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Browser;

class BrowserUrlValidator
{
    /** @param list<string> $allowedHosts */
    public function __construct(private readonly array $allowedHosts = [])
    {
    }

    public function validate(string $url): string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new BrowserToolException(
                BrowserErrorCode::INVALID_URL,
                'Only absolute HTTP and HTTPS URLs are allowed.',
            );
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new BrowserToolException(
                BrowserErrorCode::INVALID_URL,
                'URLs containing credentials are not allowed.',
            );
        }

        if (in_array($host, $this->allowedHosts, true)) {
            return $url;
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            $this->rejectPrivateAddress();
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : $this->resolveAddresses($host);

        if ($addresses === []) {
            throw new BrowserToolException(
                BrowserErrorCode::INVALID_URL,
                'The URL hostname could not be resolved.',
            );
        }

        foreach ($addresses as $address) {
            if (! filter_var(
                $address,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            )) {
                $this->rejectPrivateAddress();
            }
        }

        return $url;
    }

    /** @return list<string> */
    protected function resolveAddresses(string $host): array
    {
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false) {
            return [];
        }

        $addresses = [];
        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($address)) {
                $addresses[] = $address;
            }
        }

        return array_values(array_unique($addresses));
    }

    private function rejectPrivateAddress(): never
    {
        throw new BrowserToolException(
            BrowserErrorCode::INVALID_URL,
            'Private, loopback, link-local, and reserved network addresses are not allowed.',
        );
    }
}
