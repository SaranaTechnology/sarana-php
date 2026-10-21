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
    public const VERSION = '0.2.0';

    /** Hosts allowed over plain http, for local development only. */
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '[::1]', '::1'];

    /** Longest server error message copied into an exception message. */
    private const MAX_ERROR_MESSAGE = 300;

    private readonly string $baseUrl;
    private readonly int $timeout;
    /** @var \Closure(string, string, array<string,string>, ?string): array{0:int,1:string} */
    private readonly \Closure $transport;
    /** @var array{0:string,1:string}|null */
    private ?array $scope = null;

    /**
     * @param array{base_url?:string, timeout?:int, transport?:callable} $options
     *   base_url  defaults to PRODUCTION_URL; use SANDBOX_URL for testing.
     *             Must be https (plain http only for localhost).
     *   timeout   seconds, default 30.
     *   transport fn(string $method, string $url, array $headers, ?string $body): [int $status, string $body]
     */
    public function __construct(
        #[\SensitiveParameter] private readonly string $apiKey,
        array $options = [],
    ) {
        self::assertHeaderValue('api key', $apiKey);

        $this->baseUrl = self::checkBaseUrl($options['base_url'] ?? self::PRODUCTION_URL);

        $timeout = $options['timeout'] ?? 30;
        if (!is_int($timeout) || $timeout < 1) {
            throw new \InvalidArgumentException('Sarana: timeout must be a positive integer (seconds)');
        }
        $this->timeout = $timeout;

        $transport = $options['transport'] ?? $this->curlTransport(...);
        if (!is_callable($transport)) {
            throw new \InvalidArgumentException('Sarana: transport must be callable');
        }
        $this->transport = \Closure::fromCallable($transport);
    }

    /** Keeps the API key out of var_dump(), print_r() and debug logs. */
    public function __debugInfo(): array
    {
        return ['base_url' => $this->baseUrl, 'timeout' => $this->timeout, 'scope' => $this->scope];
    }

    /** Acts on behalf of a sub-client by sub_id (for-user-id header). */
    public function forSubClient(string $subId): self
    {
        self::assertHeaderValue('sub_id', $subId);
        $c = clone $this;
        $c->scope = ['for-user-id', $subId];
        return $c;
    }

    /** Acts on behalf of a sub-client by sub_ref (X-Sub-Client header). */
    public function forSubRef(string $subRef): self
    {
        self::assertHeaderValue('sub_ref', $subRef);
        $c = clone $this;
        $c->scope = ['X-Sub-Client', $subRef];
        return $c;
    }

    // ---------- Payments ----------

    public function paymentMethods(): mixed
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
        return $this->data('GET', '/payments/' . self::segment($id) . '/status');
    }

    // ---------- Fees ----------

    public function feeRates(): mixed
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

    /** @param array{amount:float|int, channels:string[], include_vat?:bool} $req */
    public function calculateFeeBulk(array $req): mixed
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
        return $this->data('GET', '/transactions/' . self::id($id));
    }

    /** 409 = the id exists under several sub-clients; use forSubClient(). */
    public function getTransactionByExternalId(string $externalId): array
    {
        return $this->data('GET', '/transactions/external/' . self::segment($externalId));
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
        return $this->data('GET', '/withdrawals/' . self::id($id));
    }

    public function getWithdrawalByExternalId(string $externalId): array
    {
        return $this->data('GET', '/withdrawals/external/' . self::segment($externalId));
    }

    public function cancelWithdrawal(int $id): array
    {
        return $this->data('POST', '/withdrawals/' . self::id($id) . '/cancel');
    }

    // ---------- Settlements ----------

    /** @param array{jumlah:float|int, tujuan:string, by?:string, norek?:string, bank?:string} $req */
    public function createSettlement(array $req): array
    {
        return $this->data('POST', '/settlements', $req);
    }

    // ---------- Bank channels ----------

    public function activeBankChannels(): mixed
    {
        return $this->data('GET', '/bank-channels/active');
    }

    public function getBankChannel(string $code): array
    {
        return $this->data('GET', '/bank-channels/code/' . self::segment($code));
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

    /** Apps of the account; pass one id as app_id when there is more than one. */
    public function listApps(): mixed
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
        return $this->data('GET', '/sub-clients/' . self::segment($ref), null, false);
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
        return $this->data('POST', '/sub-clients/' . self::segment($key) . '/transfers', $req, false);
    }

    /** @param array{page?:int, per_page?:int} $params */
    public function listSubClientTransfers(string $key, array $params = []): array
    {
        return $this->list('/sub-clients/' . self::segment($key) . '/transfers', $params, false);
    }

    /** @param array{page?:int, per_page?:int} $params */
    public function listGroupTransfers(array $params = []): array
    {
        return $this->list('/group-transfers', $params, false);
    }

    // ---------- internals ----------

    private function data(string $method, string $path, ?array $body = null, bool $scoped = true): mixed
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
            $message = is_array($env) && is_string($env['message'] ?? null) && $env['message'] !== ''
                ? self::truncate($env['message'])
                : 'HTTP ' . $status;
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
            // Only http(s), never file://, gopher:// and friends.
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            // A redirect would resend X-API-Key to wherever it points.
            CURLOPT_FOLLOWLOCATION => false,
            // Explicit, so a global php.ini or curl default cannot weaken TLS.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new SaranaException(0, 'Network error: ' . curl_error($ch), '');
        }
        return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), (string) $raw];
    }

    private static function checkBaseUrl(string $url): string
    {
        $url = rtrim($url, '/');
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $local = in_array($host, self::LOCAL_HOSTS, true);

        if ($host === '' || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment'])
            || !($scheme === 'https' || ($scheme === 'http' && $local))) {
            throw new \InvalidArgumentException('Sarana: base_url must be an https URL (plain http only for localhost)');
        }
        return $url;
    }

    /** Rejects CR/LF and other control characters that would let a value inject extra headers. */
    private static function assertHeaderValue(string $what, string $value): void
    {
        if ($value === '' || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new \InvalidArgumentException('Sarana: ' . $what . ' is empty or contains control characters');
        }
    }

    /** One URL path segment; "." and ".." would let an id walk to another endpoint. */
    private static function segment(string $value): string
    {
        if ($value === '' || $value === '.' || $value === '..') {
            throw new \InvalidArgumentException('Sarana: invalid id "' . $value . '"');
        }
        return rawurlencode($value);
    }

    private static function id(int $id): string
    {
        if ($id < 1) {
            throw new \InvalidArgumentException('Sarana: id must be a positive integer');
        }
        return (string) $id;
    }

    private static function truncate(string $s): string
    {
        if (strlen($s) <= self::MAX_ERROR_MESSAGE) {
            return $s;
        }
        return function_exists('mb_strcut')
            ? mb_strcut($s, 0, self::MAX_ERROR_MESSAGE, 'UTF-8') . '…'
            : substr($s, 0, self::MAX_ERROR_MESSAGE) . '…';
    }
}
