<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\SalesAssists\PriceDisclosure;

use Kanvas\Connectors\SalesAssist\PriceDisclosure\Enums\MessageIntentEnum;
use Kanvas\Connectors\SalesAssist\PriceDisclosure\Services\MessageIntentDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MessageIntentDetectorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<MessageIntentEnum>}>
     */
    public static function messages(): iterable
    {
        yield 'price en' => ['How much is the Sierra?', [MessageIntentEnum::PRICE]];
        yield 'price out the door' => ['what is the out-the-door price', [MessageIntentEnum::PRICE]];
        yield 'price msrp' => ['Is the MSRP negotiable?', [MessageIntentEnum::PRICE]];
        yield 'price es' => ['¿Cuánto cuesta la Yukon?', [MessageIntentEnum::PRICE]];
        yield 'price es unaccented' => ['cuanto vale la camioneta', [MessageIntentEnum::PRICE]];
        yield 'price es descuento' => ['tienen algún descuento?', [MessageIntentEnum::PRICE]];
        yield 'payment en' => ['What would my monthly be?', [MessageIntentEnum::PAYMENT]];
        yield 'payment down' => ['with 5k down payment what are we looking at', [MessageIntentEnum::PAYMENT]];
        yield 'payment es' => ['¿En cuánto me queda al mes?', [MessageIntentEnum::PAYMENT]];
        yield 'payment es enganche' => ['con enganche de 3 mil cuál sería la mensualidad', [MessageIntentEnum::PAYMENT]];
        yield 'add-on en' => ['Is the protection package required?', [MessageIntentEnum::ADD_ON]];
        yield 'add-on warranty' => ['do I need the extended warranty', [MessageIntentEnum::ADD_ON]];
        yield 'add-on es' => ['¿la garantía extendida es obligatoria?', [MessageIntentEnum::ADD_ON]];
        yield 'comparison en' => ['can you get me a lower monthly', [MessageIntentEnum::PAYMENT, MessageIntentEnum::COMPARISON]];
        yield 'comparison es' => ['quiero una cuota más baja', [MessageIntentEnum::PAYMENT, MessageIntentEnum::COMPARISON]];
        yield 'price and payment' => ['what is the price and what would the payments be', [MessageIntentEnum::PRICE, MessageIntentEnum::PAYMENT]];
        yield 'no intent time' => ['How much time do I have to decide?', []];
        yield 'no intent hours' => ['What time do you close today?', []];
        yield 'no intent test drive' => ['Can I schedule a test drive Saturday?', []];
        yield 'no intent es' => ['¿A qué hora abren mañana?', []];
    }

    #[DataProvider('messages')]
    public function testDetectsControlledTopics(string $text, array $expected): void
    {
        $this->assertSame($expected, new MessageIntentDetector()->detect($text));
    }
}
