<?php

declare(strict_types=1);

namespace Sarana;

/**
 * PHP client for the Sarana payment API (/api/v2).
 *
 *     $sarana = new \Sarana\Client(getenv('SARANA_API_KEY'));
 *     $inv = $sarana->createInvoice(['amount' => 150000, 'external_id' => 'ORDER-1']);
 *
 * Every call returns the `data` field of the API envelope as an array. List
 * calls return ['data' => [...], 'meta' => [...]].
 *
 * Request arrays use the API's own field names (snake_case), as documented in
 * the API reference.
 */
final class Client
{
    public const PRODUCTION_URL = 'https://api.internal.saranatechnology.com/api/v2';
    public const SANDBOX_URL = 'https://api.internal-go.saranatechnology.com/api/v2';
    public const VERSION = '0.1.0';

    private string $apiKey;
    private string $baseUrl;
    private int $timeout;
    /** @var callable(string, string, array<string,string>, ?string): array{0:int,1:string} */
    private $transport;
    /** @var array{0:string,1:string}|null */
    private ?array $scope = null;

    /**
     * @param array{base_url?:string, timeout?:int, transport?:callable} $options
     *   base_url  defaults to PRODUCTION_URL; use SANDBOX_URL for testing.
     *   timeout   seconds, default 30.
     *   transport fn(string $method, string $url, array $headers, ?string $body): [int $status, string $body]
     */
    public function __construct(string $apiKey, array $options = [])
    {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('Sarana: api key is required');
        }
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($options['base_url'] ?? self::PRODUCTION_URL, '/');
        $this->timeout = $options['timeout'] ?? 30;
        $this->transport = $options['transport'] ?? [$this, 'curlTransport'];
    }

    /** Acts on behalf of a sub-client by sub_id (for-user-id header). */
    public function forSubClient(string $subId): self
    {
        $c = clone $this;
        $c->scope = ['for-user-id', $subId];
        return $c;
    }

    /** Acts on behalf of a sub-client by sub_ref (X-Sub-Client header). */
    public function forSubRef(string $subRef): self
    {
        $c = clone $this;
        $c->scope = ['X-Sub-Client', $subRef];
        return $c;
    }

    // ---------- Payments ----------

    /** @return mixed */
    public function paymentMethods()
    {
        return $this->data('GET', '/payments/methods');
    }

    /** @param array{amount:float|int, bank_code:string, customer_name?:string, app_id?:int} $req */
    public function createVA(array $req): array
    {
        return $this->data('POST', '/payments/va', $req);
    }

    /** @param array{amount:float|int, channel_code:string, customer_phone?:string, success_url?:string, app_id?:int} $req */
    public function createEWallet(array $req): array
    {
        return $this->data('POST', '/payments/ewallet', $req);
    }

    /** @param array{amount:float|int, app_id?:int} $req */
    public function createQRIS(array $req): array
    {
        return $this->data('POST', '/payments/qris', $req);
    }

    /**
     * Hosted invoice (payment page). callback_url receives the terminal status;
     * see Callback::parsePayment().
     *
     * @param array{amount:float|int, external_id?:string, description?:string, customer_name?:string,
     *   customer_email?:string, payment_methods?:string[], expiry_duration?:int, success_url?:string,
     *   failure_url?:string, callback_url?:string, metadata?:array, app_id?:int} $req
     */
    public function createInvoice(array $req): array
    {
        return $this->data('POST', '/payments/invoice', $req);
    }

    public function paymentStatus(string $id): array
    {
        return $this->data('GET', '/payments/' . rawurlencode($id) . '/status');
    }

    // ---------- Fees ----------

    /** @return mixed */
    public function feeRates()
    {
        return $this->data('GET', '/fees/rates');
    }

    /** When paid money becomes withdrawable, per channel. */
    public function settlementSchedule(): array
    {
        return $this->data('GET', '/fees/settlement');
    }

    /** @param array{amount:float|int, channel_code:string, include_vat?:bool} $req */
    public function calculateFee(array $req): array
    {
        return $this->data('POST', '/fees/calculate', $req);
    }

    /**
     * @param array{amount:float|int, channels:string[], include_vat?:bool} $req
     * @return mixed
     */
    public function calculateFeeBulk(array $req)
    {
        return $this->data('POST', '/fees/calculate/bulk', $req);
    }

    /** @param array{amount:float|int, is_inclusive?:bool} $req */
    public function calculateVAT(array $req): array
    {
        return $this->data('POST', '/fees/calculate/vat', $req);
    }

    /** @param array{amount:float|int, bank_code:string} $req */
    public function calculateWithdrawalFee(array $req): array
    {
        return $this->data('POST', '/fees/calculate/withdrawal', $req);
    }

    /** @param array{net_amount:float|int, channel_code:string, include_vat?:bool} $req */
    public function calculateAmountToPay(array $req): array
    {
        return $this->data('POST', '/fees/calculate/amount-to-pay', $req);
    }

    // ---------- Transactions ----------

    /**
     * group=true lists the parent and every sub-client; not with a sub-client scope.
     *
     * @param array{page?:int, per_page?:int, status?:string, group?:bool} $params
     */
    public function listTransactions(array $params = []): array
    {
        return $this->list('/transactions', $params);
    }

    public function getTransaction(int $id): array
    {
        return $this->data('GET', '/transactions/' . $id);
    }

    /** 409 = the id exists under several sub-clients; use forSubClient(). */
    public function getTransactionByExternalId(string $externalId): array
    {
        return $this->data('GET', '/transactions/external/' . rawurlencode($externalId));
    }

    // ---------- Withdrawals ----------

    /** @param array{page?:int, per_page?:int, status?:string, group?:bool} $params */
    public function listWithdrawals(array $params = []): array
    {
        return $this->list('/withdrawals', $params);
    }

    /** @param array{jumlah:float|int, biaya_penarikan?:float|int, by?:string, url?:string} $req */
    public function createWithdrawal(array $req): array
    {
        return $this->data('POST', '/withdrawals', $req);
    }

    public function getWithdrawal(int $id): array
    {
        return $this->data('GET', '/withdrawals/' . $id);
    }

    public function getWithdrawalByExternalId(string $externalId): array
    {
        return $this->data('GET', '/withdrawals/external/' . rawurlencode($externalId));
    }

    public function cancelWithdrawal(int $id): array
    {
        return $this->data('POST', '/withdrawals/' . $id . '/cancel');
    }

    // ---------- Settlements ----------

    /** @param array{jumlah:float|int, tujuan:string, by?:string, norek?:string, bank?:string} $req */
    public function createSettlement(array $req): array
    {
        return $this->data('POST', '/settlements', $req);
    }

    // ---------- Bank channels ----------

    /** @return mixed */
    public function activeBankChannels()
    {
        return $this->data('GET', '/bank-channels/active');
    }

    public function getBankChannel(string $code): array
    {
        return $this->data('GET', '/bank-channels/code/' . rawurlencode($code));
    }

    // ---------- Account ----------

    public function getAccount(): array
    {
        return $this->data('GET', '/account');
    }

    /**
     * Sub-clients cannot change norek/bank (403).
     *
     * @param array{nama?:string, email?:string, alamat?:string, pic?:string, no_telp_pic?:string,
     *   email_pic?:string, norek?:string, bank?:string} $req
     */
    public function updateAccount(array $req): array
    {
        return $this->data('PUT', '/account', $req);
    }

    public function getBalance(): array
    {
        return $this->data('GET', '/account/balance');
    }

    /**
     * Apps of the account; pass one id as app_id when there is more than one.
     *
     * @return mixed
     */
    public function listApps()
    {
        return $this->data('GET', '/apps');
    }

    // ---------- Sub-clients (always as the parent account) ----------

    public function balanceSummary(): array
    {
        return $this->data('GET', '/account/balance-summary', null, false);
    }

    /** @param array{page?:int, per_page?:int} $params */
    public function listSubClients(array $params = []): array
    {
        return $this->list('/sub-clients', $params, false);
    }

    public function getSubClient(string $ref): array
    {
        return $this->data('GET', '/sub-clients/' . rawurlencode($ref), null, false);
    }

    /**
     * Moves balance between the parent and a sub-client (sub_id or sub_ref).
     * Check the returned `status`: HTTP 202 comes back with status "unknown",
     * meaning Xendit gave no clear answer. Do NOT retry that one; a retry can
     * move the money twice.
     *
     * @param array{arah:'ke_sub'|'ke_induk', jumlah:float|int, catatan?:string} $req
     */
    public function transferSubClient(string $key, array $req): array
    {
        return $this->data('POST', '/sub-clients/' . rawurlencode($key) . '/transfers', $req, false);
    }

    /** @param array{page?:int, per_page?:int} $params */
    public function listSubClientTransfers(string $key, array $params = []): array
    {
        return $this->list('/sub-clients/' . rawurlencode($key) . '/transfers', $params, false);
    }

    /** @param array{page?:int, per_page?:int} $params */
    public function listGroupTransfers(array $params = []): array
    {
        return $this->list('/group-transfers', $params, false);
    }

    // ---------- internals ----------

    /** @return mixed */
    private function data(string $method, string $path, ?array $body = null, bool $scoped = true)
    {
        $env = $this->request($method, $path, [], $body, $scoped);
        return $env['data'] ?? null;
    }

    private function list(string $path, array $params, bool $scoped = true): array
    {
        $env = $this->request('GET', $path, $params, null, $scoped);
        return [
            'data' => is_array($env['data'] ?? null) ? $env['data'] : [],
            'meta' => $env['meta'] ?? null,
        ];
    }

    private function request(string $method, string $path, array $query, ?array $body, bool $scoped): array
    {
        $url = $this->baseUrl . $path;
        $q = [];
        foreach (['group', 'page', 'per_page', 'status'] as $k) {
            if (!isset($query[$k]) || $query[$k] === '' || $query[$k] === false) {
                continue;
            }
            $q[$k] = $query[$k] === true ? 'true' : (string) $query[$k];
        }
        if ($q) {
            $url .= '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
        }

        $headers = [
            'X-API-Key' => $this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => 'sarana-php/' . self::VERSION,
        ];
        $payload = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $payload = json_encode((object) $body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if ($scoped && $this->scope !== null) {
            $headers[$this->scope[0]] = $this->scope[1];
        }

        [$status, $raw] = ($this->transport)($method, $url, $headers, $payload);

        $env = json_decode($raw, true);
        if ($status < 200 || $status >= 300) {
            $message = is_array($env) && !empty($env['message']) ? (string) $env['message'] : 'HTTP ' . $status;
            throw new SaranaException($status, $message, $raw);
        }
        if (!is_array($env)) {
            throw new SaranaException($status, 'Response is not JSON', $raw);
        }
        return $env;
    }

    /**
     * @param array<string,string> $headers
     * @return array{0:int,1:string}
     */
    private function curlTransport(string $method, string $url, array $headers, ?string $body): array
    {
        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = $k . ': ' . $v;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new SaranaException(0, 'Network error: ' . $err, '');
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$status, (string) $raw];
    }
}
