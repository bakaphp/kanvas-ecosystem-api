<?php

declare(strict_types=1);

namespace Kanvas\Templates\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Facades\Blade;
use Kanvas\Apps\Models\Apps;
use Kanvas\Templates\Repositories\TemplatesRepository;

class RenderTemplateAction
{
    /**
     * Construct function.
     */
    public function __construct(
        protected ?AppInterface $app = null,
        protected ?CompanyInterface $company = null
    ) {
        $this->app = $app ?? app(Apps::class);
    }

    /**
     * Invoke function.
     */
    public function execute(string $templateName, array $templateParams): string
    {
        /**
         * @psalm-suppress PossiblyNullArgument
         */
        $template = TemplatesRepository::getByName($templateName, $this->app, $this->company);
        $notificationTemplate = $template->template;

        if ($template->hasParentTemplate()) {
            $parentTemplate = $template->parentTemplate()->firstOrFail();

            $notificationTemplate = str_replace(
                '[body]',
                $notificationTemplate,
                $parentTemplate->template
            );
        }

        return Blade::render(
            $notificationTemplate,
            $templateParams
        );
    }
}
