# sarana-php

Client PHP untuk API pembayaran Sarana (`/api/v2`). Butuh PHP 7.4 ke atas dengan `ext-curl` dan `ext-json`, tanpa dependency lain.

```bash
composer require saranatechnology/sarana-php
```

## Pakai

```php
use Sarana\Client;
use Sarana\SaranaException;

$sarana = new Client(getenv('SARANA_API_KEY'));
// Untuk tes ke Xendit sandbox:
// $sarana = new Client($key, ['base_url' => Client::SANDBOX_URL]);

try {
    $inv = $sarana->createInvoice([
        'amount' => 150000,
        'external_id' => 'ORDER-1001',
        'callback_url' => 'https://tokoku.id/sarana/callback',
    ]);
    echo $inv['invoice_url'];
} catch (SaranaException $e) {
    error_log($e->getStatus() . ' ' . $e->getMessage());
}
```

Tiap method mengembalikan isi field `data` dari response API sebagai array. Method list (`listTransactions`, `listWithdrawals`, dan lainnya) mengembalikan `['data' => [...], 'meta' => [...]]`. Key array request sama dengan dokumentasi API (`external_id`, `bank_code`, `jumlah`).

`client_id` tidak perlu dikirim. API mengambilnya dari API key.

## Sub-client

```php
$outlet = $sarana->forSubRef('OUTLET-01');    // header X-Sub-Client
// atau $sarana->forSubClient('sub_3f9a...')  // header for-user-id

$outlet->createQRIS(['amount' => 25000]);
$outlet->getBalance();

// Pindah saldo selalu dari akun induk:
$sarana->transferSubClient('OUTLET-01', ['arah' => 'ke_sub', 'jumlah' => 500000]);
```

Kalau `transferSubClient` mengembalikan `status: "unknown"` (HTTP 202), jangan diulang. Mengulang bisa memindahkan uang dua kali. Admin Sarana yang mencocokkan transfer itu.

## Callback

```php
use Sarana\Callback;

// Tanpa argumen: baca php://input dan $_SERVER.
$cb = Callback::parsePayment();
http_response_code(200);

// Callback tidak ditandatangani: cek ulang ke API sebelum menandai lunas.
$tx = $sarana->getTransactionByExternalId($cb['body']['client_external_id']);
if ($tx['status'] === 'paid') {
    // tandai order lunas (idempoten: callback yang sama bisa datang lagi)
}
```

Di Laravel: `Callback::parsePayment($request->getContent(), $request->headers->all())`.

Callback invoice dikirim saat status `PAID`, `SETTLED`, atau `EXPIRED`. Status pending tidak dikirim, jadi pakai `paymentStatus()` untuk polling. Transaksi milik sub-client membawa `sub_id` dan `sub_ref`.

## Daftar method

| Grup | Method |
|---|---|
| Payments | `paymentMethods`, `createVA`, `createEWallet`, `createQRIS`, `createInvoice`, `paymentStatus` |
| Fees | `feeRates`, `settlementSchedule`, `calculateFee`, `calculateFeeBulk`, `calculateVAT`, `calculateWithdrawalFee`, `calculateAmountToPay` |
| Transactions | `listTransactions`, `getTransaction`, `getTransactionByExternalId` |
| Withdrawals | `listWithdrawals`, `createWithdrawal`, `getWithdrawal`, `getWithdrawalByExternalId`, `cancelWithdrawal` |
| Settlements | `createSettlement` |
| Bank channels | `activeBankChannels`, `getBankChannel` |
| Account | `getAccount`, `updateAccount`, `getBalance`, `listApps` |
| Sub-clients | `balanceSummary`, `listSubClients`, `getSubClient`, `transferSubClient`, `listSubClientTransfers`, `listGroupTransfers` |

## Lisensi

MIT
