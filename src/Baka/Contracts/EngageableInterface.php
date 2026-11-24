<?php

declare(strict_types=1);

namespace Baka\Contracts;

use Kanvas\Templates\Contracts\ExposesTemplateData;

/**
 * A model an ActionEngine engagement can reference: one person + one of these. The engagement page
 * renders toTemplateData(), never the row, so secrets on the model never reach a public link.
 * A new implementation also needs an alias in EngagementReferenceMap.
 */
interface EngageableInterface extends ExposesTemplateData
{
    public function getId(): mixed;

    public function getUuid(): string;

    public function getEngagementApp(): AppInterface;

    public function getEngagementCompany(): CompanyInterface;

    /**
     * Unsanitized; the engagement action passes it through Str::sanitizeChannelName().
     */
    public function getEngagementChannelSlug(): string;

    /**
     * The GraphQL type the EngagementReference union resolves to.
     */
    public function getGraphTypeName(): string;
}
