<?php

declare(strict_types=1);

namespace Baka\Enums;

/**
 * Mail domains that identify a provider, never a company: thousands of unrelated people share one, so
 * neither a website built from it nor an organization matched by it says anything about who they are.
 */
enum PublicEmailDomainEnum
{
    private const array DOMAINS = [
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

    /**
     * @return list<string>
     */
    public static function list(): array
    {
        return self::DOMAINS;
    }

    public static function isPublic(string $domain): bool
    {
        return in_array(strtolower(trim($domain)), self::DOMAINS, true);
    }
}
