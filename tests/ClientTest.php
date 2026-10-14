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
}
