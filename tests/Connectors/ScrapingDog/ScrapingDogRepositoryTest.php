<?php

declare(strict_types=1);

namespace Tests\Connectors\ScrapingDog;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Container\Container;
use Kanvas\Connectors\ScrapingDog\Repositories\ScrapingDogRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ScrapingDogRepositoryTest extends TestCase
{
    public function testFailedScrapesAreLoggedAndTheNextCategoryCanStillBeScraped(): void
    {
        $container = Container::getInstance();
        $testContainer = new Container();
        Container::setInstance($testContainer);

        try {
            $logger = $this->createMock(LoggerInterface::class);
            $logger->expects($this->exactly(2))->method('warning')->with(
                'ScrapingDog AI scrape failed',
                $this->callback(fn (array $context): bool => $context === [
                    'url' => 'https://www.amazon.com/books',
                    'status' => 502,
                ] || $context === [
                    'url' => 'https://www.amazon.com/books',
                    'status' => null,
                ]),
            );
            $testContainer->instance('log', $logger);

            $handler = new MockHandler([
                new Response(502, [], 'Bad Gateway'),
                new ConnectException('Connection timed out', new Request('GET', 'https://api.scrapingdog.com/scrape')),
                new Response(200, [], '[{"name":"Book","sku":"123","price":10}]'),
            ]);
            $repository = new class (new Client(['handler' => HandlerStack::create($handler)])) extends ScrapingDogRepository {
                public function __construct(Client $client)
                {
                    $this->client = $client;
                    $this->defaultParams = ['api_key' => 'secret-api-key'];
                }
            };

            $this->assertSame([], $repository->getCategoryProducts('https://www.amazon.com/books'));
            $this->assertSame([], $repository->getBestSellerCategories('https://www.amazon.com/books'));
            $this->assertSame(
                [['name' => 'Book', 'sku' => '123', 'price' => 10]],
                $repository->getCategoryProducts('https://www.amazon.com/books'),
            );
        } finally {
            Container::setInstance($container);
        }
    }
}
