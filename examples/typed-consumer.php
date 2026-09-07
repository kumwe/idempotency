<?php

declare(strict_types=1);

use Kumwe\CanonicalJson\CanonicalEncoder;
use Kumwe\Idempotency\IdempotencyKey;
use Kumwe\Idempotency\IdempotencyLedger;
use Kumwe\Idempotency\IdempotencyRecord;
use Kumwe\Idempotency\IdempotencyResult;

require $argv[1] ?? dirname(__DIR__) . '/vendor/autoload.php';

// A closed fixture corpus for this executable example, never a production encoder.
$encoder = new class implements CanonicalEncoder {
    public function encode(mixed $value): string
    {
        return match ($value) {
            [] => '[]',
            ['ok' => true] => '{"ok":true}',
            default => throw new InvalidArgumentException('Value is outside the example fixture corpus.'),
        };
    }
    public function digest(mixed $value): string
    {
        return hash('sha256', $this->encode($value));
    }
};

// Single-process contract demonstration. Durable atomic storage and clocks remain host responsibilities.
$ledger = new class implements IdempotencyLedger {
    private array $records = [];
    private function id(string $subject, string $operation, string $key): string
    {
        return json_encode([$subject, $operation, $key], JSON_THROW_ON_ERROR);
    }
    public function reserve(string $subject, string $operation, string $key, string $requestDigest, string $authorizationFingerprint, string $ownerToken): bool
    {
        $id = $this->id($subject, $operation, $key);
        if (isset($this->records[$id])) {
            return false;
        }
        $this->records[$id] = ['id' => $id, 'request_digest' => $requestDigest, 'authorization_fingerprint' => $authorizationFingerprint, 'owner_token' => $ownerToken, 'state' => 'in_progress', 'expires_at' => time() + 86400, 'lease_until' => time() + 30];
        return true;
    }
    public function find(string $subject, string $operation, string $key): ?array
    {
        return $this->records[$this->id($subject, $operation, $key)] ?? null;
    }
    public function takeOverExpired(string $id, string $requestDigest, string $authorizationFingerprint, string $ownerToken): bool
    {
        return isset($this->records[$id]) && $this->records[$id]['expires_at'] <= time()
            && $this->claim($id, $requestDigest, $authorizationFingerprint, $ownerToken);
    }
    public function takeOverFailed(string $id, string $requestDigest, string $authorizationFingerprint, string $ownerToken): bool
    {
        return isset($this->records[$id]) && $this->records[$id]['state'] === 'failed'
            && hash_equals($this->records[$id]['request_digest'], $requestDigest)
            && $this->claim($id, $requestDigest, $authorizationFingerprint, $ownerToken);
    }
    public function takeOverStale(string $id, string $authorizationFingerprint, string $ownerToken): bool
    {
        return isset($this->records[$id]) && $this->records[$id]['state'] === 'in_progress'
            && $this->records[$id]['lease_until'] <= time()
            && $this->claim($id, $this->records[$id]['request_digest'], $authorizationFingerprint, $ownerToken);
    }
    private function claim(string $id, string $digest, string $authorization, string $owner): bool
    {
        $this->records[$id] = ['id' => $id, 'request_digest' => $digest, 'authorization_fingerprint' => $authorization, 'owner_token' => $owner, 'state' => 'in_progress', 'expires_at' => time() + 86400, 'lease_until' => time() + 30];
        return true;
    }
    public function complete(string $subject, string $operation, string $key, string $ownerToken, int $status, string $body, array $headers): bool
    {
        $id = $this->id($subject, $operation, $key);
        $row = $this->records[$id] ?? null;
        if ($row === null || $row['state'] !== 'in_progress' || !hash_equals($row['owner_token'], $ownerToken) || $row['lease_until'] <= time()) {
            return false;
        }
        $this->records[$id] = [...$row, 'state' => 'completed', 'status' => $status, 'body' => $body, 'body_digest' => hash('sha256', $body), 'headers' => $headers];
        return true;
    }
    public function release(string $subject, string $operation, string $key, string $ownerToken): bool
    {
        $id = $this->id($subject, $operation, $key);
        $row = $this->records[$id] ?? null;
        if ($row === null || $row['state'] !== 'in_progress' || !hash_equals($row['owner_token'], $ownerToken)) {
            return false;
        }
        unset($this->records[$id]);
        return true;
    }
};
$key = IdempotencyKey::fromString('example:0001');
$created = new DateTimeImmutable('2026-08-04T12:00:00Z');
$record = IdempotencyRecord::begin($key, 'actor:site:org', 'example.create', [], $created, $created->modify('+1 day'), $encoder);
$result = new IdempotencyResult(201, ['ok' => true], $encoder);
$first = $ledger->reserve($record->subject(), $record->operation(), $key->value(), $record->requestDigest(), 'authorization-snapshot', 'owner-1');
$collision = $ledger->reserve($record->subject(), $record->operation(), $key->value(), $record->requestDigest(), 'authorization-snapshot', 'owner-2');
$completed = $ledger->complete($record->subject(), $record->operation(), $key->value(), 'owner-1', $result->statusCode(), $encoder->encode($result->body()), []);
$replayed = $record->complete($result)->replay([], $created->modify('+1 hour'));
if (!$first || $collision || !$completed || $replayed->body() !== ['ok' => true] || $ledger->release($record->subject(), $record->operation(), $key->value(), 'owner-2')) {
    throw new RuntimeException('Ledger or replay contract failed.');
}
echo "First claim, collision, ownership and replay verified.\n";
