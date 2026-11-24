<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intras;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Support\CompanyScope;
use Kanvas\Connectors\Intras\Enums\SipgoRecordKindEnum;
use Kanvas\Connectors\Intras\Support\SipgoReference;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Connectors\Integration\Intras\Concerns\CreatesSipgoLinkedVersion;
use Tests\TestCase;

/**
 * What a person pastes from SIPGO and what it means. The URL shapes and code prefixes were read off the
 * live app on 2026-11-09 (`/events-versions/7160/edit` shows "Código de la Versión: VE7160").
 */
final class SipgoReferenceTest extends TestCase
{
    use CreatesSipgoLinkedVersion;

    /**
     * @return iterable<string, array{0: string, 1: SipgoRecordKindEnum, 2: int}>
     */
    public static function references(): iterable
    {
        yield 'version link with a sub tab' => ['https://erp.example.test/events-versions/7160/participants', SipgoRecordKindEnum::VERSION, 7160];
        yield 'version edit link' => ['https://erp.example.test/events-versions/7160/edit', SipgoRecordKindEnum::VERSION, 7160];
        yield 'version link without a tab' => ['erp.example.test/events-versions/7160', SipgoRecordKindEnum::VERSION, 7160];
        yield 'event link' => ['https://erp.example.test/events/3576/edit', SipgoRecordKindEnum::EVENT, 3576];
        yield 'event versions list link' => ['https://erp.example.test/events/3576/events-versions/list?q=(events_id%3A3576)', SipgoRecordKindEnum::EVENT, 3576];
        yield 'participant link' => ['https://erp.example.test/participants/80239/attended-events', SipgoRecordKindEnum::PARTICIPANT, 80239];
        yield 'company link' => ['https://erp.example.test/companies/11608/edit', SipgoRecordKindEnum::COMPANY, 11608];
        yield 'proposal link' => ['https://erp.example.test/inhouseproposals/9404/edit', SipgoRecordKindEnum::PROPOSAL, 9404];
        yield 'facilitator link' => ['https://erp.example.test/facilitators/211/edit', SipgoRecordKindEnum::FACILITATOR, 211];
        yield 'version code' => ['VE7160', SipgoRecordKindEnum::VERSION, 7160];
        yield 'event code' => ['EV3576', SipgoRecordKindEnum::EVENT, 3576];
        yield 'participant code as people type it' => ['PA-80239', SipgoRecordKindEnum::PARTICIPANT, 80239];
        yield 'participant code lowercase with a space' => ['pa 80239', SipgoRecordKindEnum::PARTICIPANT, 80239];
        yield 'company code' => ['EM11608', SipgoRecordKindEnum::COMPANY, 11608];
        yield 'proposal code' => ['PI9404', SipgoRecordKindEnum::PROPOSAL, 9404];
    }

    #[DataProvider('references')]
    public function testParsesLinksAndCodes(string $input, SipgoRecordKindEnum $kind, int $legacyId): void
    {
        $reference = SipgoReference::parse($input);

        $this->assertNotNull($reference, $input);
        $this->assertSame($kind, $reference->kind);
        $this->assertSame($legacyId, $reference->legacyId);
        $this->assertFalse($reference->assumedKind);
    }

    public function testABareNumberIsReadAsAVersionAndSaysSo(): void
    {
        $reference = SipgoReference::parse(' 7160 ');

        $this->assertNotNull($reference);
        $this->assertSame(SipgoRecordKindEnum::VERSION, $reference->kind);
        $this->assertSame(7160, $reference->legacyId);
        $this->assertTrue($reference->assumedKind);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function nonReferences(): iterable
    {
        yield 'a name' => ['HELLO WELLNESS'];
        yield 'an unknown prefix' => ['XX123'];
        yield 'an unknown segment' => ['https://erp.example.test/manager/companies/12'];
        yield 'a zero id' => ['VE0'];
        yield 'empty' => [''];
    }

    #[DataProvider('nonReferences')]
    public function testRejectsWhatIsNotAReference(string $input): void
    {
        $this->assertNull(SipgoReference::parse($input), $input);
    }

    public function testCodeAndUrlRoundTrip(): void
    {
        $reference = SipgoReference::parse('https://erp.example.test/events-versions/7160/participants');

        $this->assertSame('VE7160', $reference->code());
        $this->assertSame('https://erp.example.test/events-versions/7160/edit', $reference->url('https://erp.example.test/'));
        $this->assertSame('facilitador 211', SipgoReference::parse('/facilitators/211/edit')->code());
    }

    public function testResolvesTheKanvasRowThatCarriesTheLegacyIdInsideTheTenant(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();
        $legacyId = random_int(9_000_000, 9_999_999);

        $version = $this->createSipgoLinkedVersion($app, $company, $legacyId);

        $found = SipgoReference::parse('VE' . $legacyId)->resolve($app, CompanyScope::ownCompanyOnly($app, $company));

        $this->assertNotNull($found);
        $this->assertSame($version->getId(), $found->getId());
        $this->assertSame('VE' . $legacyId, SipgoReference::codeOf($found, SipgoRecordKindEnum::VERSION));

        $this->assertNull(
            SipgoReference::parse('VE' . ($legacyId + 1))->resolve($app, CompanyScope::ownCompanyOnly($app, $company)),
            'an id nothing carries resolves to nothing'
        );
    }

    public function testDoesNotResolveAnotherCompanysRow(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();
        $otherCompany = Companies::factory()->create(['users_id' => static::$cachedUser->getId()]);
        $legacyId = random_int(9_000_000, 9_999_999);

        $this->createSipgoLinkedVersion($app, $company, $legacyId);

        $this->assertNull(
            SipgoReference::parse('VE' . $legacyId)->resolve($app, CompanyScope::ownCompanyOnly($app, $otherCompany)),
            'the same SIPGO id read from another company must not reach this company\'s row'
        );
    }
}
