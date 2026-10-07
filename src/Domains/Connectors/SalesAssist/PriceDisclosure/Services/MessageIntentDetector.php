<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\PriceDisclosure\Services;

use Kanvas\Connectors\SalesAssist\PriceDisclosure\Enums\MessageIntentEnum;

/**
 * Keyword classifier for the controlled topics, in English and Spanish. Deterministic on purpose:
 * the model may write the reply, but whether a disclosure applies has to be decided by something a
 * test can pin down and an auditor can read.
 */
class MessageIntentDetector
{
    /** @var array<string, list<string>> */
    private const array PATTERNS = [
        MessageIntentEnum::PRICE->value => [
            '\bprices?\b',
            '\bpricing\b',
            '\bcosts?\b',
            '\bhow much (?:is|for|does|would|are|will)\b',
            '\bmsrp\b',
            '\bsticker\b',
            '\bdiscounts?\b',
            '\brebates?\b',
            '\bdoc(?:umentation)? fees?\b',
            '\bfees?\b',
            '\bprecios?\b',
            '\bcu[aá]nto (?:cuesta|vale|sale|es|ser[ií]a|cobran|piden)\b',
            '\bcostos?\b',
            '\bdescuentos?\b',
            '\brebajas?\b',
            '\bcargos?\b',
        ],
        MessageIntentEnum::OUT_THE_DOOR->value => [
            '\bout[\s\-]the[\s\-]door\b',
            '\botd\b',
            '\b(?:total|final|all[\s\-]in) (?:price|cost) with (?:tax|taxes|fees|everything)\b',
            '\b(?:with|including|incl\.?) (?:tax|taxes|title|registration|everything)\b',
            '\bprecio (?:final|total) con (?:impuestos|todo)\b',
            '\bcon (?:impuestos|placas|todo incluido)\b',
            '\btodo incluido\b',
        ],
        MessageIntentEnum::PAYMENT->value => [
            '\bmonthly\b',
            '\b(?:per|a|each) month\b',
            '\bpayments?\b',
            '\bfinanc(?:e|ing|ed)\b',
            '\bleas(?:e|ing)\b',
            '\bapr\b',
            '\binterest rate\b',
            '\bdown payment\b',
            '\bterm\b',
            '\bmensual(?:idad(?:es)?)?\b',
            '\b(?:al|por|cada) mes\b',
            '\bcuotas?\b',
            '\bfinanciamiento\b',
            '\bfinanciar\b',
            '\benganche\b',
            '\binicial\b',
            '\bplazos?\b',
            '\barrendamiento\b',
            '\btasa de inter[eé]s\b',
        ],
        MessageIntentEnum::ADD_ON->value => [
            '\badd[\s\-]?ons?\b',
            '\bprotection (?:package|plan)\b',
            '\bextended warranty\b',
            '\bservice contract\b',
            '\bgap (?:insurance|coverage)\b',
            '\bpaint protection\b',
            '\bceramic\b',
            '\btint(?:ing)?\b',
            '\bnitrogen\b',
            '\betch(?:ing)?\b',
            '\bpaquete(?:s)? (?:de )?protecci[oó]n\b',
            '\bgarant[ií]a extendida\b',
            '\bseguro gap\b',
            '\bcontrato de servicio\b',
            '\bpolarizado\b',
        ],
        MessageIntentEnum::COMPARISON->value => [
            '\b(?:lower|cheaper|smaller|less) (?:monthly|payment|per month|a month)\b',
            '\bless (?:per|a|each) month\b',
            '\b(?:cuota|mensualidad|pago) m[aá]s baj[ao]\b',
            '\bmenos (?:al|por) mes\b',
        ],
    ];

    /**
     * @return list<MessageIntentEnum>
     */
    public function detect(string $text): array
    {
        $intents = [];

        foreach (self::PATTERNS as $intent => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match('/' . $pattern . '/iu', $text) === 1) {
                    $intents[] = MessageIntentEnum::from($intent);

                    break;
                }
            }
        }

        return $intents;
    }
}
