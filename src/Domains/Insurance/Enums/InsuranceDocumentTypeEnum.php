<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Enums;

enum InsuranceDocumentTypeEnum: string
{
    case REGISTRATION = 'registration';
    case INSPECTION_VIDEO = 'inspection_video';
    case IDENTIFICATION = 'identification';
    case OTHER = 'other';
}
