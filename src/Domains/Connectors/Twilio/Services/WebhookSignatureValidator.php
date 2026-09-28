<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Services;

use Illuminate\Http\Request;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Twilio\Client;
use Throwable;
use Twilio\Security\RequestValidator;

final class WebhookSignatureValidator
{
    public static function validate(
        Request $request,
        Companies $company,
        string $expectedUrl,
    ): bool {
        $signature = (string) $request->header('X-Twilio-Signature', '');
        if ($signature === '' || $expectedUrl === '') {
            return false;
        }

        try {
            [$accountSid, $authToken] = Client::getKeysFromCompany($company);
        } catch (Throwable) {
            return false;
        }

        $parameters = self::formParameters($request);
        $payloadAccountSid = (string) ($parameters['AccountSid'] ?? '');

        if ($payloadAccountSid === '' || ! hash_equals($accountSid, $payloadAccountSid)) {
            return false;
        }

        return new RequestValidator($authToken)->validate(
            $signature,
            $expectedUrl,
            $parameters,
        );
    }

    private static function formParameters(Request $request): array
    {
        $rawBody = $request->getContent();
        if ($rawBody !== '') {
            parse_str($rawBody, $parameters);

            return is_array($parameters) ? $parameters : [];
        }

        return $request->request->all();
    }
}
