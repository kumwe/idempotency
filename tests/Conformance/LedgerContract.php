<?php

declare(strict_types=1);

namespace Kumwe\Idempotency\Tests\Conformance;

use Kumwe\Idempotency\IdempotencyLedger;
use Kumwe\Idempotency\IdempotencyPurger;
use Kumwe\Idempotency\SecretOnceIdempotencyLedger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Reusable sequential port contract. Adapter subclasses supply isolated storage and a controllable
 * clock, map their find() projections to the documented fixture columns, and seed legacy/failure rows.
 * This suite cannot establish DB locking, atomic first-writer races or external-effect retry safety.
 */
abstract class LedgerContract extends TestCase
{
    abstract protected function ledger(): IdempotencyLedger;
    abstract protected function secret(): SecretOnceIdempotencyLedger;
    abstract protected function purger(): IdempotencyPurger;
    abstract protected function at(int $seconds): void;
    abstract protected function seed(string $key, array $overrides): void;

    public function testReservationIdentityDoesNotCollideAcrossScopeOrDelimiterBoundaries(): void
    {
        $ledger = $this->ledger();
        self::assertNull($ledger->find('actor', 'operation', 'request-1'));
        foreach (
            [['actor', 'operation', 'request-1'], ['other', 'operation', 'request-1'],
            ['actor', 'other', 'request-1'], ['actor', 'operation', 'request-2'],
            ['a:b', 'c', 'request-1'], ['a', 'b:c', 'request-1']] as [$subject, $operation, $key]
        ) {
            self::assertTrue($ledger->reserve($subject, $operation, $key, hash('sha256', 'request'), 'auth', 'owner'));
            $before = $ledger->find($subject, $operation, $key);
            self::assertFalse($ledger->reserve($subject, $operation, $key, hash('sha256', 'different'), 'other-auth', 'other-owner'));
            self::assertSame($before, $ledger->find($subject, $operation, $key));
        }
    }

    public function testCompletionPreservesExactReplayAndRejectsLostOrClosedClaims(): void
    {
        $ledger = $this->ledger();
        $this->seed('request-1', []);
        $before = $ledger->find('actor', 'operation', 'request-1');
        self::assertFalse($ledger->complete('actor', 'operation', 'request-1', 'wrong-owner', 201, 'wrong', []));
        self::assertSame($before, $ledger->find('actor', 'operation', 'request-1'));
        $body = "{\"message\":\"naïve\"}\n";
        $headers = ['Content-Type' => 'application/json; charset=utf-8', 'ETag' => '"exact"'];
        self::assertTrue($ledger->complete('actor', 'operation', 'request-1', 'owner', 201, $body, $headers));
        $row = $ledger->find('actor', 'operation', 'request-1');
        self::assertSame('completed', $row['state']);
        self::assertSame(201, $row['status']);
        self::assertSame($body, $row['body']);
        self::assertSame(hash('sha256', $body), $row['body_digest']);
        self::assertSame($headers, $row['headers']);
        self::assertFalse($ledger->complete('actor', 'operation', 'request-1', 'owner', 500, 'overwrite', []));
        self::assertFalse($ledger->release('actor', 'operation', 'request-1', 'owner'));
        self::assertSame($row, $ledger->find('actor', 'operation', 'request-1'));
        $row['headers']['ETag'] = 'changed';
        self::assertSame($headers, $ledger->find('actor', 'operation', 'request-1')['headers']);
    }

    public function testLeaseBoundaryFencesFormerOwnerAndPreservesDigestOnStaleTakeover(): void
    {
        $ledger = $this->ledger();
        $this->seed('request-1', ['lease_until' => 1030]);
        $row = $ledger->find('actor', 'operation', 'request-1');
        $this->at(1029);
        self::assertFalse($ledger->takeOverStale($row['id'], 'new-auth', 'new-owner'));
        $this->at(1030);
        self::assertFalse($ledger->complete('actor', 'operation', 'request-1', 'owner', 200, 'late', []));
        self::assertTrue($ledger->takeOverStale($row['id'], 'new-auth', 'new-owner'));
        self::assertFalse($ledger->takeOverStale($row['id'], 'third-auth', 'third-owner'));
        self::assertFalse($ledger->release('actor', 'operation', 'request-1', 'owner'));
        $claimed = $ledger->find('actor', 'operation', 'request-1');
        self::assertSame($row['request_digest'], $claimed['request_digest']);
        self::assertSame('new-auth', $claimed['authorization_fingerprint']);
        self::assertTrue($ledger->complete('actor', 'operation', 'request-1', 'new-owner', 200, 'ok', []));
    }

    public function testExpiryTakeoverReplacesPayloadAndClearsPriorResult(): void
    {
        $ledger = $this->ledger();
        $this->seed('request-1', ['state' => 'completed', 'expires_at' => 1100, 'body' => 'old', 'status' => 201]);
        $id = $ledger->find('actor', 'operation', 'request-1')['id'];
        $this->at(1099);
        self::assertFalse($ledger->takeOverExpired($id, hash('sha256', 'new-digest'), 'new-auth', 'new-owner'));
        $this->at(1100);
        self::assertTrue($ledger->takeOverExpired($id, hash('sha256', 'new-digest'), 'new-auth', 'new-owner'));
        self::assertFalse($ledger->takeOverExpired($id, hash('sha256', 'other'), 'other', 'other'));
        $row = $ledger->find('actor', 'operation', 'request-1');
        self::assertSame(hash('sha256', 'new-digest'), $row['request_digest']);
        self::assertSame('in_progress', $row['state']);
        self::assertNull($row['body']);
        self::assertNull($row['status']);
    }

    public function testFailedTakeoverRequiresSamePayloadAndDoesNotStealLiveOrCompletedRecords(): void
    {
        $ledger = $this->ledger();
        $this->seed('request-1', ['state' => 'failed']);
        $row = $ledger->find('actor', 'operation', 'request-1');
        self::assertFalse($ledger->takeOverFailed($row['id'], hash('sha256', 'wrong-digest'), 'auth', 'owner-2'));
        self::assertSame($row, $ledger->find('actor', 'operation', 'request-1'));
        self::assertTrue($ledger->takeOverFailed($row['id'], $row['request_digest'], 'new-auth', 'owner-2'));
        self::assertFalse($ledger->takeOverFailed($row['id'], $row['request_digest'], 'auth', 'owner-3'));
        self::assertFalse($ledger->takeOverExpired('unknown', hash('sha256', 'request'), 'auth', 'owner'));
        self::assertFalse($ledger->takeOverFailed('unknown', hash('sha256', 'request'), 'auth', 'owner'));
        self::assertFalse($ledger->takeOverStale('unknown', 'auth', 'owner'));
    }

    public function testReleaseOnlyRemovesTheMatchingInProgressReservation(): void
    {
        $ledger = $this->ledger();
        $this->seed('request-1', []);
        self::assertFalse($ledger->release('actor', 'operation', 'request-1', 'wrong'));
        self::assertTrue($ledger->release('actor', 'operation', 'request-1', 'owner'));
        self::assertFalse($ledger->release('actor', 'operation', 'request-1', 'owner'));
        self::assertNull($ledger->find('actor', 'operation', 'request-1'));
    }

    public function testSecretCompletionReprovesFingerprintOwnerStateAndTime(): void
    {
        $ledger = $this->secret();
        self::assertNull($ledger->find('actor', 'operation', 'request-1'));
        self::assertTrue($ledger->reserve('actor', 'operation', 'request-1', hash('sha256', 'request'), 'auth', 'owner'));
        self::assertFalse($ledger->reserve('actor', 'operation', 'request-1', hash('sha256', 'request'), 'auth', 'other'));
        self::assertFalse($ledger->confirmLease('actor', 'operation', 'request-1', 'wrong', 'auth'));
        self::assertFalse($ledger->confirmLease('actor', 'operation', 'request-1', 'owner', 'wrong'));
        self::assertTrue($ledger->confirmLease('actor', 'operation', 'request-1', 'owner', 'auth'));
        self::assertFalse($ledger->complete('actor', 'operation', 'request-1', 'owner', 'wrong', 201, 'bad', []));
        self::assertTrue($ledger->complete('actor', 'operation', 'request-1', 'owner', 'auth', 201, '{"id":"token-1"}', []));
        $row = $ledger->find('actor', 'operation', 'request-1');
        self::assertSame('{"id":"token-1"}', $row['body']);
        self::assertSame(hash('sha256', $row['body']), $row['body_digest']);
        self::assertFalse($ledger->confirmLease('actor', 'operation', 'request-1', 'owner', 'auth'));
        self::assertFalse($ledger->complete('actor', 'operation', 'request-1', 'owner', 'auth', 201, 'overwrite', []));
        $ledger->release('actor', 'operation', 'request-1', 'owner');
        self::assertSame($row, $ledger->find('actor', 'operation', 'request-1'));
    }

    #[DataProvider('takeoverStates')]
    public function testSecretTakeoverRetestsEligibilityAndClearsOldResult(array $overrides, bool $allowed): void
    {
        $this->seed('request-1', $overrides);
        $ledger = $this->secret();
        $before = $ledger->find('actor', 'operation', 'request-1');
        self::assertSame($allowed, $ledger->takeOver('actor', 'operation', 'request-1', hash('sha256', 'new'), 'new-auth', 'new-owner'));
        if (!$allowed) {
            self::assertSame($before, $ledger->find('actor', 'operation', 'request-1'));
            return;
        }
        self::assertTrue($ledger->confirmLease('actor', 'operation', 'request-1', 'new-owner', 'new-auth'));
        self::assertFalse($ledger->confirmLease('actor', 'operation', 'request-1', 'owner', 'auth'));
        self::assertFalse($ledger->takeOver('actor', 'operation', 'request-1', hash('sha256', 'other'), 'other', 'other'));
        self::assertNull($ledger->find('actor', 'operation', 'request-1')['body']);
    }

    public static function takeoverStates(): iterable
    {
        yield 'live' => [[], false];
        yield 'failed' => [['state' => 'failed', 'body' => 'legacy'], true];
        yield 'lease exact boundary' => [['lease_until' => 1000], true];
        yield 'expiry exact boundary' => [['state' => 'completed', 'expires_at' => 1000, 'body' => 'legacy'], true];
        yield 'completed unexpired' => [['state' => 'completed', 'lease_until' => 900], false];
    }

    public function testSecretLeaseBoundaryAndReleaseDoNotRetainAnAbandonedReservation(): void
    {
        $this->seed('request-1', ['lease_until' => 1000]);
        $ledger = $this->secret();
        self::assertFalse($ledger->confirmLease('actor', 'operation', 'request-1', 'owner', 'auth'));
        self::assertFalse($ledger->complete('actor', 'operation', 'request-1', 'owner', 'auth', 200, 'late', []));
        $ledger->release('actor', 'operation', 'request-1', 'wrong');
        self::assertNotNull($ledger->find('actor', 'operation', 'request-1'));
        $ledger->release('actor', 'operation', 'request-1', 'owner');
        self::assertNull($ledger->find('actor', 'operation', 'request-1'));
        self::assertFalse($ledger->takeOver('actor', 'operation', 'missing', hash('sha256', 'new'), 'auth', 'owner'));
    }

    public function testLegacySecretRewriteReplacesBytesAndIntegrityDigest(): void
    {
        $this->seed('request-1', ['state' => 'completed', 'body' => '{"secret":"old","id":"token-1"}', 'status' => 201]);
        $ledger = $this->secret();
        $safe = '{"id":"token-1"}';
        $ledger->rewriteStoredResult('actor', 'operation', 'request-1', $safe);
        $row = $ledger->find('actor', 'operation', 'request-1');
        self::assertSame($safe, $row['body']);
        self::assertSame(hash('sha256', $safe), $row['body_digest']);
        self::assertSame('completed', $row['state']);
        self::assertSame(201, $row['status']);
    }

    public function testPurgerBoundsBatchesAndProtectsEachIndependentOwnershipCondition(): void
    {
        foreach (['expired-1', 'expired-2', 'expired-3'] as $key) {
            $this->seed($key, ['state' => 'completed', 'expires_at' => 1000, 'owner_token' => null, 'lease_until' => null]);
        }
        $this->seed('live-owner', ['expires_at' => 999, 'lease_until' => 999]);
        $this->seed('live-lock', ['expires_at' => 999, 'owner_token' => null, 'lease_until' => 1001]);
        $this->seed('not-expired', ['expires_at' => 1001, 'owner_token' => null, 'lease_until' => null]);
        self::assertSame(2, $this->purger()->purgeExpired(2));
        self::assertSame(1, $this->purger()->purgeExpired(2));
        self::assertSame(0, $this->purger()->purgeExpired());
        foreach (['live-owner', 'live-lock', 'not-expired'] as $key) {
            self::assertNotNull($this->ledger()->find('actor', 'operation', $key));
        }
        $this->at(1001);
        self::assertSame(2, $this->purger()->purgeExpired());
        self::assertNotNull($this->ledger()->find('actor', 'operation', 'live-owner'));
    }
}
