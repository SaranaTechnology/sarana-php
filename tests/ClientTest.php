<?php

declare(strict_types=1);

namespace Sarana\Tests;

use PHPUnit\Framework\TestCase;
use Sarana\Callback;
use Sarana\Client;
use Sarana\SaranaException;

final class ClientTest extends TestCase
{
    /** @var array<int, array{method:string,url:string,headers:array,body:?array}> */
    private array $calls = [];

    private function client(int $status, string $response): Client
    {
        $this->calls = [];
        return new Client('sk_test', [
            'base_url' => 'https://api.test/api/v2/',
            'transport' => function (string $method, string $url, array $headers, ?string $body) use ($status, $response) {
                $this->calls[] = [
                    'method' => $method,
                    'url' => $url,
                    'headers' => $headers,
                    'body' => $body === null ? null : json_decode($body, true),
                ];
                return [$status, $response];
            },
        ]);
    }

    public function testCreateInvoice(): void
    {
        $c = $this->client(201, '{"success":true,"data":{"invoice_url":"https://x"}}');
        $data = $c->createInvoice(['amount' => 15000, 'external_id' => 'ORDER-1']);

        self::assertSame('https://x', $data['invoice_url']);
        $call = $this->calls[0];
        self::assertSame('POST', $call['method']);
        self::assertSame('https://api.test/api/v2/payments/invoice', $call['url']);
        self::assertSame('sk_test', $call['headers']['X-API-Key']);
        self::assertArrayNotHasKey('for-user-id', $call['headers']);
        self::assertSame(['amount' => 15000, 'external_id' => 'ORDER-1'], $call['body']);
    }

    public function testSubClientScope(): void
    {
        $c = $this->client(200, '{"success":true,"data":{}}');

        $c->forSubClient('sub_abc')->getBalance();
        self::assertSame('sub_abc', $this->calls[0]['headers']['for-user-id']);

        $c->forSubRef('OUTLET-01')->getAccount();
        self::assertSame('OUTLET-01', $this->calls[1]['headers']['X-Sub-Client']);

        $c->forSubRef('OUTLET-01')->transferSubClient('OUTLET 01', ['arah' => 'ke_sub', 'jumlah' => 5]);
        self::assertArrayNotHasKey('X-Sub-Client', $this->calls[2]['headers']);
        self::assertSame('https://api.test/api/v2/sub-clients/OUTLET%2001/transfers', $this->calls[2]['url']);

        // The original client is not changed by forSubRef().
        $c->getAccount();
        self::assertArrayNotHasKey('X-Sub-Client', $this->calls[3]['headers']);
    }

    public function testList(): void
    {
        $c = $this->client(200, '{"success":true,"data":[{"id":1}],"meta":{"total":1}}');
        $l = $c->listTransactions(['page' => 2, 'status' => 'PAID', 'group' => true]);

        self::assertCount(1, $l['data']);
        self::assertSame(1, $l['meta']['total']);
        self::assertSame('https://api.test/api/v2/transactions?group=true&page=2&status=PAID', $this->calls[0]['url']);
    }

    public function testError(): void
    {
        $c = $this->client(422, '{"success":false,"message":"Saldo kurang"}');
        try {
            $c->createWithdrawal(['jumlah' => 1]);
            self::fail('expected exception');
        } catch (SaranaException $e) {
            self::assertSame(422, $e->getStatus());
            self::assertStringContainsString('Saldo kurang', $e->getMessage());
        }

        $this->expectException(SaranaException::class);
        $this->client(502, '<html>bad</html>')->getAccount();
    }

    public function testCallback(): void
    {
        $cb = Callback::parsePayment('{"transaction_id":"INV-1","status":"paid"}', ['For-User-Id' => 'sub_abc', 'X-Sub-Client' => 'OUTLET-01']);
        self::assertTrue($cb['is_paid']);
        self::assertSame('PAID', $cb['body']['status']);
        self::assertSame('sub_abc', $cb['sub_id']);
        self::assertSame('OUTLET-01', $cb['sub_ref']);

        $_SERVER['HTTP_X_SUB_CLIENT'] = 'S';
        $cb = Callback::parsePayment('{"status":"EXPIRED"}');
        self::assertFalse($cb['is_paid']);
        self::assertSame('S', $cb['sub_ref']);
        unset($_SERVER['HTTP_X_SUB_CLIENT']);
    }

    public function testRejectsHeaderInjection(): void
    {
        $c = $this->client(200, '{"success":true,"data":{}}');
        foreach (["OUTLET\r\nX-API-Key: other", "a\nb", "a\0b", ''] as $bad) {
            try {
                $c->forSubRef($bad);
                self::fail('expected InvalidArgumentException for ' . json_encode($bad));
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('sub_ref', $e->getMessage());
            }
        }

        $this->expectException(\InvalidArgumentException::class);
        new Client("sk_test\r\nX-Evil: 1");
    }

    public function testRejectsDotSegments(): void
    {
        $c = $this->client(200, '{"success":true,"data":{}}');
        foreach (['..', '.', ''] as $bad) {
            try {
                $c->paymentStatus($bad);
                self::fail('expected InvalidArgumentException for ' . json_encode($bad));
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('invalid id', $e->getMessage());
            }
        }
        self::assertCount(0, $this->calls);

        // Slashes inside an id stay encoded, so they cannot add path segments.
        $c->getTransactionByExternalId('../../account');
        self::assertSame('https://api.test/api/v2/transactions/external/..%2F..%2Faccount', $this->calls[0]['url']);
    }

    public function testRejectsNonPositiveIds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client(200, '{"success":true,"data":{}}')->getWithdrawal(0);
    }

    public function testBaseUrlMustBeHttps(): void
    {
        foreach (['http://api.example.com/api/v2', 'file:///etc/passwd', 'ftp://x/y', 'https://user:pw@x/api', 'not a url'] as $bad) {
            try {
                new Client('sk_test', ['base_url' => $bad]);
                self::fail('expected InvalidArgumentException for ' . $bad);
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('base_url', $e->getMessage());
            }
        }

        // Plain http is fine for local development.
        new Client('sk_test', ['base_url' => 'http://localhost:8080/api/v2']);
        new Client('sk_test', ['base_url' => 'http://127.0.0.1/api/v2']);
        self::assertTrue(true);
    }

    public function testApiKeyHiddenFromDumps(): void
    {
        $c = new Client('sk_live_secret');
        self::assertStringNotContainsString('sk_live_secret', print_r($c, true));
    }

    public function testLongErrorMessageIsTruncated(): void
    {
        $c = $this->client(500, json_encode(['message' => str_repeat('x', 5000)]));
        try {
            $c->getAccount();
            self::fail('expected exception');
        } catch (SaranaException $e) {
            self::assertLessThan(400, strlen($e->getMessage()));
            self::assertSame(5000 + strlen('{"message":""}'), strlen($e->getBody()));
        }
    }
}
