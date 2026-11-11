<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ShortIo\Handlers;

use Baka\Support\Str;
use Illuminate\Support\Facades\Validator;
use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\ShortIo\Client;
use Kanvas\Connectors\ShortIo\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Override;

class ShortIoHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $apiKey = Str::trimToNull((string) ($this->data['api_key'] ?? ''))
            ?? throw new ValidationException('The short.io API key is required.');
        $domain = $this->domain();
        $linkPageBaseUrl = $this->linkPageBaseUrl();

        if (! in_array($domain, new Client($apiKey, $domain)->domainHostnames(), true)) {
            throw new ValidationException("{$domain} is not a domain of this short.io account.");
        }

        $this->company->setEncrypted(ConfigurationEnum::API_KEY->value, $apiKey);
        $this->company->set(ConfigurationEnum::DOMAIN->value, $domain);

        if ($linkPageBaseUrl === null) {
            $this->company->del(ConfigurationEnum::LINK_PAGE_BASE_URL->value);
        } else {
            $this->company->set(ConfigurationEnum::LINK_PAGE_BASE_URL->value, $linkPageBaseUrl);
        }

        return true;
    }

    #[Override]
    protected function companySettingKeys(): array
    {
        return ConfigurationEnum::companyKeys();
    }

    /**
     * Accepts a bare hostname or a pasted URL.
     */
    private function domain(): string
    {
        $domain = Str::trimToNull((string) ($this->data['domain'] ?? ''))
            ?? throw new ValidationException('The short.io domain is required.');

        $host = parse_url(str_contains($domain, '://') ? $domain : 'https://' . $domain, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            throw new ValidationException("{$domain} is not a valid domain.");
        }

        return strtolower($host);
    }

    private function linkPageBaseUrl(): ?string
    {
        $url = Str::trimToNull((string) ($this->data['link_page_base_url'] ?? ''));
        if ($url === null) {
            return null;
        }

        $parts = parse_url($url);
        $invalid = Validator::make(['url' => $url], ['url' => 'url:https'])->fails()
            || ! is_array($parts)
            || isset($parts['query'])
            || isset($parts['fragment'])
            || isset($parts['user']);

        if ($invalid) {
            throw new ValidationException('The link page base URL must be an https:// address with no query string or fragment.');
        }

        return rtrim($url, '/');
    }
}
