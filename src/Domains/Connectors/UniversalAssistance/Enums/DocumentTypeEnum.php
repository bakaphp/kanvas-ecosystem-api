<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalAssistance\Enums;

enum DocumentTypeEnum: string
{
    case PASSPORT = 'Pasaporte';
    case OTHER = 'Otros';
    case DNI = 'DNI';
    case IDENTITY_CARD = 'CI';
    case LE = 'LE';
    case LC = 'LC';
    case FOREIGN_DOCUMENT = 'Documento Extranjero';
    case RUT = 'RUT';
    case CPF = 'CPF';
    case RG = 'RG';
}
