<?php

declare(strict_types=1);

namespace Kumwe\Idempotency\Tests\Fixture;

use InvalidArgumentException;
use Kumwe\Idempotency\IdempotencyLedger;
use Kumwe\Idempotency\IdempotencyPurger;

/** Deterministic single-process test fixture, never a durable production adapter. */
final class LedgerStore implements IdempotencyLedger, IdempotencyPurger
{
    public const LEASE = 30;
    public const RETENTION = 3600;
    public int $now = 1000;
    public array $rows = [];

    public function identity(string $subject, string $operation, string $key): string
    {
        return json_encode([$subject, $operation, $key], JSON_THROW_ON_ERROR);
    }

    public function reserve(string $subject, string $operation, string $key, string $requestDigest, string $authorizationFingerprint, string $ownerToken): bool
    {
        $id = $this->identity($subject, $operation, $key);
        return !isset($this->rows[$id]) && $this->claim($id, $requestDigest, $authorizationFingerprint, $ownerToken);
    }

    public function find(string $subject, string $operation, string $key): ?array
    {
        return $this->rows[$this->identity($subject, $operation, $key)] ?? null;
    }

    public function claim(string $id, string $digest, string $fingerprint, string $owner): bool
    {
        $this->rows[$id] = [
            'id' => $id, 'request_digest' => $digest, 'authorization_fingerprint' => $fingerprint,
            'owner_token' => $owner, 'state' => 'in_progress', 'lease_until' => $this->now + self::LEASE,
            'expires_at' => $this->now + self::RETENTION, 'body' => null, 'body_digest' => null,
            'status' => null, 'headers' => [],
        ];
        return true;
    }

    public function takeOverExpired(string $id, string $requestDigest, string $authorizationFingerprint, string $ownerToken): bool
    {
        return isset($this->rows[$id]) && $this->rows[$id]['expires_at'] <= $this->now
            && $this->claim($id, $requestDigest, $authorizationFingerprint, $ownerToken);
    }

    public function takeOverFailed(string $id, string $requestDigest, string $authorizationFingerprint, string $ownerToken): bool
    {
        return isset($this->rows[$id]) && $this->rows[$id]['state'] === 'failed'
            && hash_equals($this->rows[$id]['request_digest'], $requestDigest)
            && $this->claim($id, $requestDigest, $authorizationFingerprint, $ownerToken);
    }

    public function takeOverStale(string $id, string $authorizationFingerprint, string $ownerToken): bool
    {
        return isset($this->rows[$id]) && $this->rows[$id]['state'] === 'in_progress'
            && $this->rows[$id]['lease_until'] <= $this->now
            && $this->claim($id, $this->rows[$id]['request_digest'], $authorizationFingerprint, $ownerToken);
    }

    public function owns(string $id, string $owner): bool
    {
        $row = $this->rows[$id] ?? null;
        return $row !== null && $row['state'] === 'in_progress'
            && hash_equals($row['owner_token'] ?? '', $owner) && $row['lease_until'] > $this->now;
    }

    public function complete(string $subject, string $operation, string $key, string $ownerToken, int $status, string $body, array $headers): bool
    {
        $id = $this->identity($subject, $operation, $key);
        if (!$this->owns($id, $ownerToken)) {
            return false;
        }
        $this->rows[$id] = [...$this->rows[$id], 'state' => 'completed', 'owner_token' => null,
            'lease_until' => null, 'status' => $status, 'body' => $body,
            'body_digest' => hash('sha256', $body), 'headers' => $headers];
        return true;
    }

    public function release(string $subject, string $operation, string $key, string $ownerToken): bool
    {
        $id = $this->identity($subject, $operation, $key);
        $row = $this->rows[$id] ?? null;
        if ($row === null || $row['state'] !== 'in_progress' || !hash_equals($row['owner_token'] ?? '', $ownerToken)) {
            return false;
        }
        unset($this->rows[$id]);
        return true;
    }

    public function purgeExpired(int $batchSize = 1000): int
    {
        if ($batchSize < 1) {
            throw new InvalidArgumentException('A purge batch must be positive.');
        }
        $deleted = 0;
        foreach ($this->rows as $id => $row) {
            if (
                $row['expires_at'] <= $this->now && ($row['owner_token'] ?? null) === null
                && ($row['lease_until'] ?? 0) <= $this->now
            ) {
                unset($this->rows[$id]);
                if (++$deleted === $batchSize) {
                    break;
                }
            }
        }
        return $deleted;
    }
}
