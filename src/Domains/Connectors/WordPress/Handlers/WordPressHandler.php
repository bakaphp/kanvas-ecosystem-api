<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WordPress\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\WordPress\Enums\ConfigurationEnum;
use Kanvas\Connectors\WordPress\Enums\PostStatusEnum;
use Kanvas\Connectors\WordPress\RestClient;
use Kanvas\Exceptions\ValidationException;
use Override;

class WordPressHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $siteUrl = trim((string) ($this->data['site_url'] ?? ''));
        $username = trim((string) ($this->data['username'] ?? ''));
        $applicationPassword = trim((string) ($this->data['application_password'] ?? ''));

        if ($siteUrl === '' || $username === '' || $applicationPassword === '') {
            throw new ValidationException('WordPress site url, username and application password are required');
        }

        // Credentials are per-site, and a site belongs to a tenant — never app-wide.
        $this->company->set(ConfigurationEnum::SITE_URL->value, rtrim($siteUrl, '/'));
        $this->company->set(ConfigurationEnum::USERNAME->value, $username);
        $this->company->set(ConfigurationEnum::APPLICATION_PASSWORD->value, $applicationPassword);

        $this->storeOptionalDefaults();

        $user = new RestClient($this->app, $this->company)->me();

        if (empty($user['capabilities']['publish_posts'])) {
            throw new ValidationException(
                'The WordPress user "' . $username . '" cannot publish posts — grant it at least the Author role'
            );
        }

        return true;
    }

    private function storeOptionalDefaults(): void
    {
        if (isset($this->data['default_post_status'])) {
            $status = PostStatusEnum::tryFromMixed($this->data['default_post_status']);

            if ($status === null) {
                throw new ValidationException('Invalid WordPress default post status: ' . $this->data['default_post_status']);
            }

            $this->company->set(ConfigurationEnum::DEFAULT_POST_STATUS->value, $status->value);
        }

        foreach ([
            'default_author_id' => ConfigurationEnum::DEFAULT_AUTHOR_ID,
            'default_categories' => ConfigurationEnum::DEFAULT_CATEGORIES,
            'default_tags' => ConfigurationEnum::DEFAULT_TAGS,
            'allow_term_creation' => ConfigurationEnum::ALLOW_TERM_CREATION,
        ] as $input => $key) {
            if (isset($this->data[$input])) {
                $this->company->set($key->value, $this->data[$input]);
            }
        }
    }
}
