<?php

declare(strict_types=1);

namespace Goddy\PayPal\Tests;

use Goddy\PayPal\Http\HttpClientInterface;
use Goddy\PayPal\Http\HttpResponse;
use Goddy\PayPal\PayPalClient;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PayPalClientTest extends TestCase
{
    public function testCreateOrderUsesOAuthAndReturnsPayPalResponse(): void
    {
        $http = new FakeHttpClient([
            new HttpResponse(200, '{"access_token":"test-token","expires_in":3600}'),
            new HttpResponse(201, '{"id":"ORDER-123","status":"CREATED"}'),
        ]);
        $client = new PayPalClient('client-id', 'client-secret', true, $http);

        $order = $client->createOrder('12.50', 'eur', 'https://shop.example/return', 'https://shop.example/cancel');

        self::assertSame('ORDER-123', $order['id']);
        self::assertCount(2, $http->requests);
        self::assertSame('https://api-m.sandbox.paypal.com/v1/oauth2/token', $http->requests[0]['url']);
        self::assertSame('Basic ' . base64_encode('client-id:client-secret'), $http->requests[0]['headers']['Authorization']);
        self::assertSame('Bearer test-token', $http->requests[1]['headers']['Authorization']);

        $payload = json_decode($http->requests[1]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('EUR', $payload['purchase_units'][0]['amount']['currency_code']);
        self::assertSame('12.50', $payload['purchase_units'][0]['amount']['value']);
        self::assertSame('CAPTURE', $payload['intent']);
    }

    public function testCreateOrderRejectsInvalidAmountBeforeMakingRequest(): void
    {
        $http = new FakeHttpClient([]);
        $client = new PayPalClient('client-id', 'client-secret', true, $http);

        $this->expectException(InvalidArgumentException::class);
        $client->createOrder('0', 'USD', 'https://shop.example/return', 'https://shop.example/cancel');
    }

    public function testCaptureOrderCallsTheOrdersCaptureEndpoint(): void
    {
        $http = new FakeHttpClient([
            new HttpResponse(200, '{"access_token":"test-token","expires_in":3600}'),
            new HttpResponse(201, '{"id":"ORDER-123","status":"COMPLETED"}'),
        ]);
        $client = new PayPalClient('client-id', 'client-secret', true, $http);

        $capture = $client->captureOrder('ORDER-123');

        self::assertSame('COMPLETED', $capture['status']);
        self::assertSame(
            'https://api-m.sandbox.paypal.com/v2/checkout/orders/ORDER-123/capture',
            $http->requests[1]['url'],
        );
        self::assertNull($http->requests[1]['body']);
    }
}

final class FakeHttpClient implements HttpClientInterface
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $requests = [];

    /** @param list<HttpResponse> $responses */
    public function __construct(private array $responses)
    {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body');
        if ($this->responses === []) {
            throw new \RuntimeException('Aucune réponse de test disponible.');
        }

        return array_shift($this->responses);
    }
}
