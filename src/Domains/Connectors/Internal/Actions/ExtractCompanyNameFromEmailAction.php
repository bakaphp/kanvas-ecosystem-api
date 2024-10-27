<?php

namespace Kanvas\Connectors\Internal\Actions;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ExtractCompanyNameFromEmailAction
{
    protected array $publicEmailProviders = [];

    public function __construct()
    {
        $this->setPublicEmailProviders();
    }

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

            // Split the title by `-` or `|` and take the first part
            $titleParts = preg_split('/[-|]/', $title);
            $cleanTitle = trim($titleParts[0]);

            return Str::title($cleanTitle);
        }

        return null;
    }

    protected function setPublicEmailProviders(): void
    {
        $this->publicEmailProviders = [
            // Major international providers
            'gmail.com',
            'yahoo.com',
            'hotmail.com',
            'outlook.com',
            'aol.com',
            'icloud.com',
            'live.com',
            'msn.com',
            'mail.com',
            'protonmail.com',

            // Additional popular providers
            'yandex.com',
            'yandex.ru',
            'zoho.com',
            'yahoo.co.uk',
            'yahoo.co.jp',
            'yahoo.fr',
            'yahoo.de',
            'yahoo.it',
            'yahoo.es',
            'gmx.com',
            'gmx.de',
            'gmx.net',
            'tutanota.com',
            'tutanota.de',
            'fastmail.com',
            'mailbox.org',
            'me.com',
            'mac.com',
            'web.de',
            'inbox.com',
            'seznam.cz',
            'wp.pl',
            'comcast.net',
            'verizon.net',
            'rediffmail.com',
            'libero.it',
            'free.fr',
            'laposte.net',
            'orange.fr',
            'optonline.net',
            'rocketmail.com',
            'att.net',
            'sbcglobal.net',
            'rambler.ru',
            'mail.ru',
            'cox.net',
            'wanadoo.fr',
            'earthlink.net',
            'btinternet.com',
            'charter.net',
            'shaw.ca',
            'bellsouth.net',

            // Common disposable/temporary email providers
            'tempmail.com',
            'temp-mail.org',
            'guerrillamail.com',
            'guerrillamail.net',
            'guerrillamail.org',
            'guerrillamail.biz',
            'sharklasers.com',
            'grr.la',
            'maildrop.cc',
            'harakirimail.com',
            'yopmail.com',
            'yopmail.fr',
            'yopmail.net',
            'cool.fr.nf',
            'jetable.org',
            'nospam.ze.tc',
            'nomail.xl.cx',
            'mega.zik.dj',
            'speed.1s.fr',
            'courriel.fr.nf',
            'moncourrier.fr.nf',
            'monemail.fr.nf',
            'monmail.fr.nf',
            '10minutemail.com',
            '10minutemail.net',
            '10minutemail.org',
            'tempinbox.com',
            'mailinator.com',
            'mailinator.net',
            'mailinator.org',
            'mailinator.com',
            'mailinator.net',
            'trashmail.net',
            'trashmail.com',
            'trashmail.org',
            'spamgourmet.com',
            'tempmail.net',
            'throwawaymail.com',
            'dispostable.com',
            'byom.de',
            'wegwerfmail.de',
            'wegwerfmail.net',
            'wegwerfmail.org',
            'fakeinbox.com',
            'fakeinbox.net',
            'fakeinbox.org',
            'trbvm.com',
            'drdrb.net',
            'tempmail2.com',
            'deadaddress.com',
            'spambox.us',
            'tempemail.net',
            'fakemailgenerator.com',
            'armyspy.com',
            'cuvox.de',
            'dayrep.com',
            'einrot.com',
            'fleckens.hu',
            'gustr.com',
            'jourrapide.com',
            'rhyta.com',
            'superrito.com',
            'teleworm.us',
        ];
    }
}
