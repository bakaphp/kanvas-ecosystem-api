<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Internal\Actions;

use Baka\Enums\PublicEmailDomainEnum;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ExtractCompanyNameFromEmailAction
{
    public function execute(string $email): ?string
    {
        $domain = $this->extractDomain($email);

        if (empty($domain)) {
            return null;
        }

        if ($this->isPublicEmailProvider($domain)) {
            return null;
        }

        if ($this->isDomainValid($domain)) {
            $companyName = $this->scrapeWebsiteForCompanyName($domain);

            if ($companyName) {
                return Str::limit($companyName, 128);
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
        return PublicEmailDomainEnum::isPublic($domain);
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

            // Decode HTML entities like &amp; &lt; etc.
            $title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5);

            // Split the title by `-` or `|` and take the first part
            $titleParts = preg_split('/[-|]/', $title);
            $cleanTitle = trim($titleParts[0]);

            // Return the title in proper case
            return Str::title($cleanTitle);
        }

        return null;
    }
}
