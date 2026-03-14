<?php

namespace Drupal\Tests\dkan_mcp\Unit\Tools;

use Drupal\dkan_mcp\Tools\SearchTools;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

class SearchToolsTest extends TestCase {

  protected function createTools(ClientInterface $client, ?SymfonyRequest $request = NULL): SearchTools {
    $requestStack = $this->createMock(RequestStack::class);
    $requestStack->method('getCurrentRequest')->willReturn($request);
    return new SearchTools($client, $requestStack);
  }

  public function testSearchDatasetsSuccess(): void {
    $body = json_encode([
      'results' => [
        ['identifier' => 'abc-123', 'title' => 'Test Dataset'],
      ],
      'total' => 1,
    ]);

    $client = $this->createMock(ClientInterface::class);
    $client->method('request')->willReturn(new Response(200, [], $body));

    $request = SymfonyRequest::create('http://example.com');
    $tools = $this->createTools($client, $request);
    $result = $tools->searchDatasets('test');

    $this->assertCount(1, $result['results']);
    $this->assertEquals(1, $result['total']);
    $this->assertEquals(1, $result['page']);
    $this->assertEquals(10, $result['page_size']);
  }

  public function testSearchDatasetsHttpError(): void {
    $client = $this->createMock(ClientInterface::class);
    $client->method('request')->willThrowException(
      new RequestException('Connection failed', new Request('GET', '/api/1/search'))
    );

    $request = SymfonyRequest::create('http://example.com');
    $tools = $this->createTools($client, $request);
    $result = $tools->searchDatasets('test');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('Connection failed', $result['error']);
  }

  public function testSearchDatasetsPageSizeClamping(): void {
    $body = json_encode(['results' => [], 'total' => 0]);

    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->once())
      ->method('request')
      ->with(
        'GET',
        $this->anything(),
        $this->callback(function ($options) {
          return $options['query']['page-size'] === 50
            && $options['query']['page'] === 1;
        })
      )
      ->willReturn(new Response(200, [], $body));

    $request = SymfonyRequest::create('http://example.com');
    $tools = $this->createTools($client, $request);
    $result = $tools->searchDatasets('test', 0, 200);

    $this->assertEquals(1, $result['page']);
    $this->assertEquals(50, $result['page_size']);
  }

  public function testSearchDatasetsDrushFallbackUrl(): void {
    $body = json_encode(['results' => [], 'total' => 0]);

    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->once())
      ->method('request')
      ->with(
        'GET',
        $this->stringContains('http://localhost/api/1/search'),
        $this->anything(),
      )
      ->willReturn(new Response(200, [], $body));

    // No Symfony request (Drush context), no DRUSH_OPTIONS_URI.
    $tools = $this->createTools($client);
    $result = $tools->searchDatasets('test');

    $this->assertArrayHasKey('results', $result);
  }

}
