<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Guild\Leads\Models\LeadAttempt;

readonly class CreateLeadAttemptAction
{
    /**
     * __construct.
     */
    public function __construct(
        protected array $request,
        protected array $headers,
        protected CompanyInterface $company,
        protected AppInterface $app,
        protected string $ip,
        protected string $source
    ) {
    }

    public function execute(): LeadAttempt
    {
        // Create a copy of the request so we can safely modify it
        $sanitizedRequest = $this->request;

        // Remove any file uploads from the request before storing
        if (isset($sanitizedRequest['input']['files'])) {
            foreach ($sanitizedRequest['input']['files'] as $key => $file) {
                if (isset($file['file'])) {
                    unset($sanitizedRequest['input']['files'][$key]['file']);
                }
            }
        }

        return LeadAttempt::create([
            'companies_id' => $this->company->getId(),
            'apps_id'      => $this->app->getId(),
            'header'       => $this->headers,
            'request'      => $sanitizedRequest, // Use the sanitized request
            'ip'           => $this->ip,
            'source'       => $this->source,
            'public_key'   => $this->request['public_key'] ?? null,
            'processed'    => 0,
        ]);
    }
}
