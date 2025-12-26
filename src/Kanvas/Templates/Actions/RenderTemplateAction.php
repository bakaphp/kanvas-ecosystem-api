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
    public function execute(string $templateName, array $templateParams, $bladeRenderTemplateParams = true): string
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
        // We need to avoid using the render method to replace the values on the template, sometimes it does not work as expected for push notifications
        if (! $bladeRenderTemplateParams) {
            foreach ($templateParams as $key => $value) {
                $notificationTemplate = str_replace(
                    "{{{$key}}}",
                    $value,
                    $notificationTemplate
                );
            }
        }
        return Blade::render(
            $notificationTemplate,
            $bladeRenderTemplateParams ? $templateParams : []
        );
    }
}
