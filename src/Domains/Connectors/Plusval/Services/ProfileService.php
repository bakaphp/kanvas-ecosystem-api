<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Plusval\Services;

use GuzzleHttp\Exception\GuzzleException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Plusval\Client;
use Kanvas\Connectors\Plusval\Helpers\PhoneHelper;
use Kanvas\Exceptions\ValidationException;

class ProfileService
{
    protected Client $client;

    public function __construct(
        protected Apps $app,
        protected Companies $company
    ) {
        $this->client = new Client($app, $company);
    }

    /**
     * Get profile by phone number
     *
     * @param string $phone Sender phone number (e.g., "+1 809-864-6241")
     * @throws GuzzleException
     * @throws ValidationException
     */
    public function getProfileByPhone(string $phone): array
    {
        if (empty($phone)) {
            throw new ValidationException('Phone number is required');
        }

        // Clean and format phone number if needed
        $phone = PhoneHelper::formatPhoneNumber($phone);

        return $this->client->getProfile($phone);
    }
}
