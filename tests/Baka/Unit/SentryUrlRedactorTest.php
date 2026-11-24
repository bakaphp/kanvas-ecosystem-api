<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use Baka\Support\SentryUrlRedactor;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Sentry\Tracing\Span;

final class SentryUrlRedactorTest extends TestCase
{
    private const string KEY = '441b1f5a-9d4a-4130-aaca-8fab892baad8';
    private const string URL = 'https://fedotest-api.webposonline.com/api/fedo/ak/v1/test/sendFileToProcess/YPSPUFLH/' . self::KEY;

    public function test_a_webpos_key_in_the_path_is_redacted_and_the_endpoint_stays_readable(): void
    {
        $redacted = SentryUrlRedactor::redact('POST ' . self::URL . '?x=1 failed');

        $this->assertStringNotContainsString(self::KEY, $redacted);
        $this->assertStringContainsString('/ak/v1/test/sendFileToProcess/YPSPUFLH/[redacted]?x=1 failed', $redacted);
        $this->assertStringContainsString(
            'checkRnc/[redacted]/131793916',
            SentryUrlRedactor::redact('https://fedo-api.webposonline.com/api/fedo/ak/v1/checkRnc/' . self::KEY . '/131793916')
        );
    }

    public function test_other_urls_are_left_alone(): void
    {
        $url = 'https://example.com/api/v1/items/' . self::KEY;

        $this->assertSame($url, SentryUrlRedactor::redact($url));
        $this->assertSame(
            'https://fedotest-api.webposonline.com/api/fedo/v1/CheckRnc/131793916',
            SentryUrlRedactor::redact('https://fedotest-api.webposonline.com/api/fedo/v1/CheckRnc/131793916')
        );
    }

    public function test_http_breadcrumbs_are_scrubbed(): void
    {
        $breadcrumb = new Breadcrumb(
            Breadcrumb::LEVEL_INFO,
            Breadcrumb::TYPE_HTTP,
            'http',
            null,
            ['url' => self::URL, 'http.request.method' => 'POST', 'status_code' => 400]
        );

        $scrubbed = SentryUrlRedactor::breadcrumb($breadcrumb);

        $this->assertStringNotContainsString(self::KEY, (string) json_encode($scrubbed->getMetadata()));
        $this->assertSame(400, $scrubbed->getMetadata()['status_code']);
    }

    public function test_exception_text_and_spans_are_scrubbed(): void
    {
        $event = Event::createEvent();
        $event->setExceptions([new ExceptionDataBag(new RuntimeException('cURL error 28 for ' . self::URL))]);
        $span = new Span();
        $span->setDescription('POST ' . self::URL);
        $span->setData(['url' => self::URL]);
        $event->setSpans([$span]);

        $scrubbed = SentryUrlRedactor::event($event);

        $this->assertStringNotContainsString(self::KEY, $scrubbed->getExceptions()[0]->getValue());
        $this->assertStringNotContainsString(self::KEY, (string) $scrubbed->getSpans()[0]->getDescription());
        $this->assertStringNotContainsString(self::KEY, (string) json_encode($scrubbed->getSpans()[0]->getData()));
    }

    public function test_the_app_key_header_is_scrubbed_from_the_request(): void
    {
        $event = Event::createEvent();
        $event->setRequest([
            'url' => 'https://example.test/graphql',
            'headers' => [
                'X-Kanvas-Key' => ['super-secret'],
                'x-kanvas-key' => 'super-secret',
                'Accept' => ['application/json'],
            ],
        ]);

        $headers = SentryUrlRedactor::event($event)->getRequest()['headers'];

        $this->assertSame([SentryUrlRedactor::REDACTED], $headers['X-Kanvas-Key']);
        $this->assertSame(SentryUrlRedactor::REDACTED, $headers['x-kanvas-key']);
        $this->assertSame(['application/json'], $headers['Accept']);
    }

    public function test_bindings_of_a_query_on_a_secret_column_are_scrubbed(): void
    {
        $lookup = SentryUrlRedactor::breadcrumb(new Breadcrumb(
            Breadcrumb::LEVEL_INFO,
            Breadcrumb::TYPE_DEFAULT,
            'db.sql.query',
            'select * from `apps_keys` where `client_secret_id` = ? and `is_deleted` = ? limit 1',
            ['bindings' => ['super-secret', 0], 'connectionName' => 'ecosystem', 'executionTimeMs' => 0.9]
        ));

        $this->assertSame(SentryUrlRedactor::REDACTED, $lookup->getMetadata()['bindings']);
        $this->assertSame('ecosystem', $lookup->getMetadata()['connectionName']);
        $this->assertSame(0.9, $lookup->getMetadata()['executionTimeMs']);

        $other = SentryUrlRedactor::breadcrumb(new Breadcrumb(
            Breadcrumb::LEVEL_INFO,
            Breadcrumb::TYPE_DEFAULT,
            'db.sql.query',
            'select * from `companies` where `id` = ? limit 1',
            ['bindings' => [9602]]
        ));

        $this->assertSame([9602], $other->getMetadata()['bindings']);
    }
}
