<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Humano\Enums;

/**
 * Policy term. It decides which of the two amount sets in a quote response is the
 * one the customer actually owes — see HumanoProvider::toQuoteResult.
 */
enum VigenciaEnum: string
{
    case MENSUAL = 'M';
    case BIMESTRAL = 'B';
    case TRIMESTRAL = 'T';
    case CUATRIMESTRAL = 'C';
    case SEMESTRAL = 'S';
    case NUEVE_MESES = 'N';
    case ANUAL = 'A';
    case UNICA = 'U';
    case BIANUAL = 'BA';

    public function label(): string
    {
        return match ($this) {
            self::MENSUAL => 'Mensual',
            self::BIMESTRAL => 'Bimestral',
            self::TRIMESTRAL => 'Trimestral',
            self::CUATRIMESTRAL => 'Cuatrimestral',
            self::SEMESTRAL => 'Semestral',
            self::NUEVE_MESES => '9 meses',
            self::ANUAL => 'Anual',
            self::UNICA => 'Única',
            self::BIANUAL => 'Bianual',
        };
    }
}
