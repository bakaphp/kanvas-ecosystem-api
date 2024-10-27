<?php

namespace Kanvas\Connectors\Internal\Actions;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ExtractCompanyNameFromEmailAction
{
    protected array $publicEmailProviders = [
        'gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'aol.com', 'icloud.com',
        'live.com', 'msn.com', 'mail.com', 'protonmail.com', // Add more public providers here
    ];

    public function execute(string $email): ?string
    {
        $domain = $this->extractDomain($email);

        // Skip if it's a public email provider
        if ($this->isPublicEmailProvider($domain)) {
            return null;
        }

        if ($this->isDomainValid($domain)) {
            $companyName = $this->scrapeWebsiteForCompanyName($domain);

            if ($companyName) {
                return $companyName;
            }
        }

        return null;
    }

    protected function extractDomain(string $email): string
    {
        return substr(strrchr($email, '@'), 1);
    }

    protected function isPublicEmailProvider(string $domain): bool
    {
        return in_array($domain, $this->publicEmailProviders);
    }

    protected function isDomainValid(string $domain): bool
    {
        return checkdnsrr($domain, 'A') || checkdnsrr($domain, 'MX');
    }

    protected function scrapeWebsiteForCompanyName(string $domain): ?string
    {
        $url = 'https://' . $domain;

        try {
            $response = Http::timeout(5)->get($url);

            if ($response->successful()) {
                $html = $response->body();
                $companyName = $this->extractTitleTag($html);

                return $companyName;
            }
        } catch (Exception $e) {
            return null;
        }

        return null;
    }

    protected function extractTitleTag(string $html): ?string
    {
        $matches = [];

        if (preg_match("/<title>(.*?)<\/title>/i", $html, $matches)) {
            $title = $matches[1];
            $title = Str::title($title);

            return $title;
        }

        return null;
    }
}
