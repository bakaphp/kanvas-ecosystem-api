<?php

declare(strict_types=1);

namespace Kanvas\Imports\Enums;

use Illuminate\Support\Facades\File;
use Kanvas\Imports\DataTransferObject\ImportTemplate;

enum ImportTemplateEnum: string
{
    case DEALER_VEHICLE_CSV = 'dealer_vehicle_csv';
    case BANK_STATEMENT_POPULAR_CSV = 'bank_statement_popular_csv';
    case BANK_STATEMENT_BHD_CSV = 'bank_statement_bhd_csv';
    case BANK_STATEMENT_BANRESERVAS_CSV = 'bank_statement_banreservas_csv';
    case WEBPOS_ISSUED_ECF_CSV = 'webpos_issued_ecf_csv';

    /**
     * @return list<self>
     */
    public static function bankStatementTemplates(): array
    {
        return [
            self::BANK_STATEMENT_POPULAR_CSV,
            self::BANK_STATEMENT_BHD_CSV,
            self::BANK_STATEMENT_BANRESERVAS_CSV,
        ];
    }

    public function template(): ImportTemplate
    {
        return ImportTemplate::fromDefinition(
            File::json(
                resource_path('import-templates/' . str_replace('_', '-', $this->value) . '.json'),
                JSON_THROW_ON_ERROR
            )
        );
    }
}
