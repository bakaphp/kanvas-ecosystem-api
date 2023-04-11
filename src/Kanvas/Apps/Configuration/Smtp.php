<?php

declare(strict_types=1);

namespace Kanvas\Apps\Configuration;

use Kanvas\Apps\Models\Apps;

class Smtp
{
    public Apps $app;

    public function __construct(Apps $app)
    {
        $this->app = $app;
    }

    /**
     * load
     */
    public function load(): void
    {
        config(['mail.mailers.smtp.host' => $this->app->get('smtp_host')]);
        config(['mail.mailers.smtp.port' => $this->app->get('smtp_port')]);
        config(['mail.mailers.smtp.username' => $this->app->get('smtp_username')]);
        config(['mail.mailers.smtp.password' => $this->app->get('smtp_password')]);
        config(['mail.mailers.smtp.encryption' => $this->app->get('smtp_encryption')]);
        config(['mail.mailers.from.address' => $this->app->get('smtp_fromEmail')]);
        config(['mail.mailers.from.name' => $this->app->get('smtp_fromName')]);
    }
}
