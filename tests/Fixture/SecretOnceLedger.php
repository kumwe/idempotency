<?php

declare(strict_types=1);

namespace Kumwe\Idempotency\Tests\Fixture;

use Kumwe\Idempotency\SecretOnceIdempotencyLedger;

/** Shares only fixture storage; the public secret-once port stays distinct. */
final class SecretOnceLedger implements SecretOnceIdempotencyLedger
{
    public function __construct(private LedgerStore $store)
    {
    }

    public function reserve(string $subject, string $operation, string $key, string $requestDigest, string $authorizationFingerprint, string $ownerToken): bool
    {
        return $this->store->reserve($subject, $operation, $key, $requestDigest, $authorizationFingerprint, $ownerToken);
    }

    public function find(string $subject, string $operation, string $key): ?array
    {
        return $this->store->find($subject, $operation, $key);
    }

    public function takeOver(string $subject, string $operation, string $key, string $requestDigest, string $authorizationFingerprint, string $ownerToken): bool
    {
        $row = $this->find($subject, $operation, $key);
        if (
            $row === null || !($row['expires_at'] <= $this->store->now || $row['state'] === 'failed'
            || ($row['state'] === 'in_progress' && $row['lease_until'] <= $this->store->now))
        ) {
            return false;
        }
        return $this->store->claim($row['id'], $requestDigest, $authorizationFingerprint, $ownerToken);
    }

    public function confirmLease(string $subject, string $operation, string $key, string $ownerToken, string $authorizationFingerprint): bool
    {
        $id = $this->store->identity($subject, $operation, $key);
        return $this->store->owns($id, $ownerToken)
            && hash_equals($this->store->rows[$id]['authorization_fingerprint'], $authorizationFingerprint);
    }

    public function complete(string $subject, string $operation, string $key, string $ownerToken, string $authorizationFingerprint, int $status, string $body, array $headers): bool
    {
        return $this->confirmLease($subject, $operation, $key, $ownerToken, $authorizationFingerprint)
            && $this->store->complete($subject, $operation, $key, $ownerToken, $status, $body, $headers);
    }

    public function rewriteStoredResult(string $subject, string $operation, string $key, string $body): void
    {
        $id = $this->store->identity($subject, $operation, $key);
        if (isset($this->store->rows[$id]) && $this->store->rows[$id]['state'] === 'completed') {
            $this->store->rows[$id]['body'] = $body;
            $this->store->rows[$id]['body_digest'] = hash('sha256', $body);
        }
    }

    public function release(string $subject, string $operation, string $key, string $ownerToken): void
    {
        $this->store->release($subject, $operation, $key, $ownerToken);
    }
}
