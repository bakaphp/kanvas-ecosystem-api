<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\SalesAssists;

use Kanvas\Connectors\SalesAssist\Exceptions\InvalidAdfPayloadException;
use Kanvas\Connectors\SalesAssist\Services\AdfXmlParserService;
use Tests\TestCase;

final class AdfXmlParserServiceTest extends TestCase
{
    public function testParsesAdfFollowedByProviderFooter(): void
    {
        $body = $this->carfaxAdf() . "\r\n\r\nIf you would like to unsubscribe and stop receiving these emails click here: "
            . 'https://unsubscribe.example.com/u?token=test-token-3D.';

        $data = AdfXmlParserService::toArray($body);

        $this->assertSame('test-prospect-0001', $data['adf']['prospect']['id']['@content']);
        $this->assertSame('jane.doe@example.com', $data['adf']['prospect']['customer']['contact']['email']);
    }

    public function testFooterEndingInDomainIsNotTreatedAsFilePath(): void
    {
        $data = AdfXmlParserService::toArray($this->carfaxAdf() . "\r\nLead provided by carfax.com");

        $this->assertArrayHasKey('prospect', $data['adf']);
    }

    public function testParsesAdfPrecededByForwardedText(): void
    {
        $data = AdfXmlParserService::toArray("---------- Forwarded message ---------\r\n\r\n  " . $this->carfaxAdf());

        $this->assertArrayHasKey('prospect', $data['adf']);
    }

    public function testNonAdfEmailReturnsNull(): void
    {
        $body = "Verification Code\r\nTo verify your account, enter this code in TikTok:\r\n000000\r\n"
            . 'TikTok Help Center: https://support.tiktok.com/';

        $this->assertNull(AdfXmlParserService::toArray($body));
        $this->assertNull(AdfXmlParserService::toArray(null));
        $this->assertNull(AdfXmlParserService::toArray(''));
    }

    public function testMalformedAdfThrowsWithLibxmlReason(): void
    {
        $this->expectException(InvalidAdfPayloadException::class);
        $this->expectExceptionMessageMatches('/xmlParseEntityRef/');

        AdfXmlParserService::toArray('<adf><prospect><comments>Tom & Jerry</comments></prospect></adf>');
    }

    public function testAdfWithoutClosingTagThrows(): void
    {
        $this->expectException(InvalidAdfPayloadException::class);
        $this->expectExceptionMessage('missing closing </adf> tag');

        AdfXmlParserService::toArray("<?xml version=\"1.0\"?>\r\n<adf><prospect>");
    }

    private function carfaxAdf(): string
    {
        return <<<'XML'
            <?xml version="1.0"?>
            <?adf version="1.0"?>
              <adf>
                <prospect>
                  <id sequence="1" source="CARFAX, INC">test-prospect-0001</id>
                  <requestdate>2026-09-15T00:06:31.674-04:00</requestdate>
                  <customer>
                    <contact>
                      <name part="first">Jane</name>
                      <name part="last">Doe</name>
                      <email>jane.doe@example.com</email>
                      <phone preferredcontact="1" type="voice">555-010-0000</phone>
                    </contact>
                    <timeframe />
                    <comments>
                      <![CDATA[Hi, I'm interested in your 2022 Toyota Tacoma SR5.
             Listing: www.carfax.com/vehicle/TESTVIN0000000001
             Lead provided by carfax.com.]]>
                    </comments>
                  </customer>
                </prospect>
              </adf>
            XML;
    }
}
