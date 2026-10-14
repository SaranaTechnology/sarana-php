<?php

declare(strict_types=1);

namespace Sarana;

/**
 * Reads the callbacks Sarana POSTs to your URLs.
 *
 * Callbacks are not signed: confirm a payment with getTransactionByExternalId()
 * (or paymentStatus()) before releasing goods. The same invoice can arrive more
 * than once (again once the final fee is known), so handle it idempotently.
 */
final class Callback
{
    /**
     * @param string|null $rawBody raw request body; defaults to php://input
     * @param array<string,string>|null $headers request headers; defaults to $_SERVER
     * @return array{body:array, status:string, is_paid:bool, sub_id:?string, sub_ref:?string}
     */
    public static function parsePayment(?string $rawBody = null, ?array $headers = null): array
    {
        $body = self::decode($rawBody);
        $status = strtoupper((string) ($body['status'] ?? ''));
        $body['status'] = $status;

        return [
            'body' => $body,
            'status' => $status,
            'is_paid' => $status === 'PAID' || $status === 'SETTLED',
            'sub_id' => self::header($headers, 'for-user-id'),
            'sub_ref' => self::header($headers, 'x-sub-client'),
        ];
    }

    /**
     * Withdrawal body: status, di_transfer_oleh, transfer_at.
     *
     * @return array{body:array, sub_id:?string, sub_ref:?string}
     */
    public static function parseWithdrawal(?string $rawBody = null, ?array $headers = null): array
    {
        return [
            'body' => self::decode($rawBody),
            'sub_id' => self::header($headers, 'for-user-id'),
            'sub_ref' => self::header($headers, 'x-sub-client'),
        ];
    }

    private static function decode(?string $raw): array
    {
        $raw ??= (string) file_get_contents('php://input');
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            throw new \InvalidArgumentException('Sarana: invalid callback body');
        }
        return $body;
    }

    private static function header(?array $headers, string $name): ?string
    {
        if ($headers === null) {
            $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
            $v = $_SERVER[$key] ?? null;
            return is_string($v) && $v !== '' ? $v : null;
        }
        foreach ($headers as $k => $v) {
            if (strcasecmp((string) $k, $name) === 0) {
                $v = is_array($v) ? ($v[0] ?? null) : $v;
                return $v === null || $v === '' ? null : (string) $v;
            }
        }
        return null;
    }
}
