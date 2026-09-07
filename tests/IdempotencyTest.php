<?php

declare(strict_types=1);

namespace Kumwe\Idempotency\Tests;

use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use Kumwe\CanonicalJson\CanonicalEncoder;
use Kumwe\Idempotency\IdempotencyKey;
use Kumwe\Idempotency\IdempotencyRecord;
use Kumwe\Idempotency\IdempotencyResult;
use Kumwe\Idempotency\IdempotencyState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IdempotencyTest extends TestCase
{
    public function testCanonicalRequestReplayAndImmutableTransitions(): void
    {
        $encoder = $this->createStub(CanonicalEncoder::class);
        $encoder->method('digest')->willReturnMap([
            [['b' => 2, 'a' => 1], hash('sha256', '{"a":1,"b":2}')],
            [['a' => 1, 'b' => 2], hash('sha256', '{"a":1,"b":2}')],
        ]);
        $encoder->method('encode')->willReturn('{"id":"content-1","version":1}');
        $created = new DateTimeImmutable('2026-08-04T12:00:00Z');
        $expires = $created->modify('+1 day');
        $record = IdempotencyRecord::begin(IdempotencyKey::fromString('request:1234'), 'actor-1', 'content.create', ['b' => 2, 'a' => 1], $created, $expires, $encoder);
        $result = new IdempotencyResult(201, ['id' => 'content-1', 'version' => 1], $encoder);
        $completed = $record->complete($result);
        self::assertSame(IdempotencyState::IN_PROGRESS, $record->state());
        self::assertSame(IdempotencyState::COMPLETED, $completed->state());
        self::assertSame($result, $completed->replay(['a' => 1, 'b' => 2], $expires->modify('-1 microsecond')));
        self::assertSame('kumwe-canonical-json/generic-v1', $record->fingerprintProfile());
        self::assertSame($record->fingerprintProfile(), $result->fingerprintProfile());
        self::assertSame($created, $completed->createdAt());
        self::assertSame($expires, $completed->expiresAt());
        self::assertSame('actor-1', $completed->subject());
        self::assertSame('content.create', $completed->operation());
        self::assertSame('request:1234', $completed->key()->value());
        self::assertSame(hash('sha256', '{"id":"content-1","version":1}'), $result->bodyDigest());
        self::assertSame(201, $result->statusCode());
        self::assertSame(['id' => 'content-1', 'version' => 1], $result->body());
        self::assertTrue($completed->isExpiredAt($expires));
        $this->expectExceptionMessage('The idempotency record has expired.');
        $completed->replay(['a' => 1, 'b' => 2], $expires);
    }

    #[DataProvider('invalidKeys')]
    public function testHostileKeyBounds(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        IdempotencyKey::fromString($key);
    }

    public static function invalidKeys(): iterable
    {
        foreach (['', '1234567', str_repeat('a', 129), '.1234567', '1234 567', "12345678\n", 'é12345678', "1234\0abcd"] as $key) {
            yield [$key];
        }
    }

    public function testKeyGrammarBoundariesAndHeaderTrimming(): void
    {
        foreach (['12345678', str_repeat('z', 128), 'abc._:-Z'] as $value) {
            $key = IdempotencyKey::fromString($value);
            self::assertSame($value, (string) $key);
            self::assertTrue($key->equals(IdempotencyKey::fromHeader(" \t" . $value . "\r\n")));
        }
        self::assertFalse(IdempotencyKey::fromString('request-a')->equals(IdempotencyKey::fromString('request-b')));
    }

    #[DataProvider('terminalStates')]
    public function testClosedRecordsNeverReopen(string $state, string $transition): void
    {
        $encoder = $this->encoder();
        $record = $this->record($encoder);
        $closed = $state === 'failed' ? $record->fail() : $record->complete(new IdempotencyResult(200, [], $encoder));
        $this->expectException(DomainException::class);
        if ($transition === 'fail') {
            $closed->fail();
        } else {
            $closed->complete(new IdempotencyResult(200, [], $encoder));
        }
    }

    public static function terminalStates(): iterable
    {
        foreach (['failed', 'completed'] as $state) {
            foreach (['fail', 'complete'] as $transition) {
                yield [$state, $transition];
            }
        }
    }

    #[DataProvider('nonReplayableStates')]
    public function testNonCompletedStateRefusesReplay(bool $failed, string $message): void
    {
        $record = $this->record($this->encoder());
        if ($failed) {
            $record = $record->fail();
        }
        $this->expectExceptionMessage($message);
        $record->replay([], new DateTimeImmutable('2026-08-04T13:00:00Z'));
    }

    public static function nonReplayableStates(): iterable
    {
        yield [false, 'The idempotent operation is still in progress.'];
        yield [true, 'The idempotent operation did not complete successfully.'];
    }

    public function testPayloadConflictPrecedesReplay(): void
    {
        $encoder = $this->createStub(CanonicalEncoder::class);
        $encoder->method('digest')->willReturn(hash('sha256', '[]'), hash('sha256', '[1]'));
        $record = $this->record($encoder);
        $this->expectExceptionMessage('The idempotency key has already been used for a different request.');
        $record->assertRequestMatches([1]);
    }

    public function testEncoderRefusalIsPropagated(): void
    {
        $encoder = $this->createStub(CanonicalEncoder::class);
        $encoder->method('digest')->willThrowException(new InvalidArgumentException('generic-v1 value.object'));
        $this->expectExceptionMessage('generic-v1 value.object');
        $this->record($encoder);
    }

    public function testResultDetachesReferencesAndRoundTripsThroughJson(): void
    {
        $value = 'first';
        $body = ['value' => &$value];
        $encoder = $this->createMock(CanonicalEncoder::class);
        $encoder->expects(self::once())->method('encode')->with($body)->willReturn('{"value":"first"}');
        $result = new IdempotencyResult(200, $body, $encoder);
        $value = 'mutated';
        self::assertSame(['value' => 'first'], $result->body());
        self::assertSame($result->body(), json_decode(json_encode($result->body(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
    }

    #[DataProvider('invalidStatus')]
    public function testInvalidResponseStatuses(int $status): void
    {
        $this->expectException(InvalidArgumentException::class);
        new IdempotencyResult($status, [], $this->encoder());
    }

    public static function invalidStatus(): iterable
    {
        yield [99];
        yield [600];
    }

    #[DataProvider('invalidClaims')]
    public function testClaimIdentityAndTimeBoundaries(string $subject, string $operation, string $expires): void
    {
        $this->expectException(InvalidArgumentException::class);
        IdempotencyRecord::begin(IdempotencyKey::fromString('request:1234'), $subject, $operation, [], new DateTimeImmutable('2026-08-04T12:00:00Z'), new DateTimeImmutable($expires), $this->encoder());
    }

    public static function invalidClaims(): iterable
    {
        yield ['', 'op', '2026-08-05T12:00:00Z'];
        yield ['subject', " \t", '2026-08-05T12:00:00Z'];
        yield ['subject', 'op', '2026-08-04T12:00:00Z'];
        yield ['subject', 'op', '2026-08-04T11:59:59Z'];
    }

    private function encoder(): CanonicalEncoder
    {
        $encoder = $this->createStub(CanonicalEncoder::class);
        $encoder->method('digest')->willReturn(hash('sha256', '[]'));
        $encoder->method('encode')->willReturn('[]');
        return $encoder;
    }

    private function record(CanonicalEncoder $encoder): IdempotencyRecord
    {
        return IdempotencyRecord::begin(IdempotencyKey::fromString('request:1234'), 'subject', 'operation', [], new DateTimeImmutable('2026-08-04T12:00:00Z'), new DateTimeImmutable('2026-08-05T12:00:00Z'), $encoder);
    }
}
