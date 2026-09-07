# Public API

The source contracts below define parameters, return values, exceptions and invariants. [The machine manifest](../resources/public-api/v1.json) freezes signatures and is checked by `composer api`. All values are immutable; static helpers have no retained state and perform no I/O. Host port implementations own their documented side effects and concurrency guarantees. The injected canonical encoder must implement GenericV1; no service is resolved globally.

## Kumwe\Idempotency\IdempotencyKey

Source: [src/IdempotencyKey.php](../src/IdempotencyKey.php).

### `fromString`

```php
public static function fromString(string $value): self
```

```text
@param   string  $value  Caller-supplied replay identity of one replay-protected operation.

@throws  InvalidArgumentException  When the value is not 8 to 128 transport-safe ASCII characters.

@since   0.1.0
```

### `fromHeader`

```php
public static function fromHeader(string $value): self
```

```text
Parse a transport header using the same key grammar after trimming whitespace.

@param string $value Header value as received.
@return self Validated canonical key.
@throws InvalidArgumentException When the trimmed value violates the key grammar.
@since 0.1.0
```

### `value`

```php
public function value(): string
```

```text
@return string 8 to 128 transport-safe ASCII characters. @since 0.1.0
```

### `equals`

```php
public function equals(self $other): bool
```

```text
Compare replay identities in constant time. @param self $other Key claimed by another request. @since 0.1.0
```

### `__toString`

```php
public function __toString(): string
```

```text
@since 0.1.0
```

## Kumwe\Idempotency\IdempotencyLedger

Source: [src/IdempotencyLedger.php](../src/IdempotencyLedger.php).

### `reserve`

```php
public function reserve(
        string $subject,
        string $operation,
        string $key,
        string $requestDigest,
        string $authorizationFingerprint,
        string $ownerToken,
    ): bool
```

```text
Reserve a key for this request by inserting an in-progress record, losing cleanly on a collision.

The implementation dates the processing lease and the retention window from its own clock, and
must let the storage's uniqueness rule decide between simultaneous first attempts.

@param   string  $subject                   Principal the record is keyed against.
@param   string  $operation                 Method and path pair the key is scoped to.
@param   string  $key                       The client's validated `Idempotency-Key`.
@param   string  $requestDigest             Digest of the request the key is being spent on.
@param   string  $authorizationFingerprint  Credential and site fingerprint stored with the claim.
@param   string  $ownerToken                Random token marking the reservation as this request's.

@return  bool  True when the reservation is now this request's; false when a record for this
         subject, operation and key already existed.

@since   0.1.0
```

### `find`

```php
public function find(string $subject, string $operation, string $key): ?array
```

```text
Read the stored record a failed reservation collided with.

@param   string  $subject    Principal the record is keyed against.
@param   string  $operation  Method and path pair the key is scoped to.
@param   string  $key        The client's `Idempotency-Key`.

@return  ?array<string, mixed>  The stored record keyed by column name — identity, digests, state,
         lock instant, result columns and expiry, each exactly as stored — or null when the record
         vanished between the collision and this read.

@since   0.1.0
```

### `takeOverExpired`

```php
public function takeOverExpired(
        string $id,
        string $requestDigest,
        string $authorizationFingerprint,
        string $ownerToken,
    ): bool
```

```text
Claim a record whose retention window has already closed, re-proving expiry inside the write.

The stored digest is replaced, because an expired record no longer speaks for any particular
request: its key is free for whatever content the new claimant carries.

@param   string  $id                        Identifier of the record being taken over.
@param   string  $requestDigest             Digest of this request, replacing the expired one.
@param   string  $authorizationFingerprint  Fingerprint stored with the new reservation.
@param   string  $ownerToken                Token proving the new reservation is this request's.

@return  bool  True when this request now owns the record; false when it was revived concurrently.

@since   0.1.0
```

### `takeOverFailed`

```php
public function takeOverFailed(
        string $id,
        string $requestDigest,
        string $authorizationFingerprint,
        string $ownerToken,
    ): bool
```

```text
Claim a record left behind by an attempt that ended in failure, re-proving that state in the write.

@param   string  $id                        Identifier of the record being retried.
@param   string  $requestDigest             Digest of this request, equal to the stored one.
@param   string  $authorizationFingerprint  Fingerprint stored with the new reservation.
@param   string  $ownerToken                Token proving the new reservation is this request's.

@return  bool  True when this request now owns the record; false when another retry claimed it first.

@since   0.1.0
```

### `takeOverStale`

```php
public function takeOverStale(string $id, string $authorizationFingerprint, string $ownerToken): bool
```

```text
Claim an in-progress record whose processing lease has run out, re-proving the lapse in the write.

No digest is passed, because the caller has already proved its digest equals the stored one, so
there is nothing to rewrite.

@param   string  $id                        Identifier of the record being taken over.
@param   string  $authorizationFingerprint  Fingerprint stored with the new reservation.
@param   string  $ownerToken                Token proving the new reservation is this request's.

@return  bool  True when this request now owns the record; false when the lease was still held or
         another attempt claimed it first.

@since   0.1.0
```

### `complete`

```php
public function complete(
        string $subject,
        string $operation,
        string $key,
        string $ownerToken,
        int $status,
        string $body,
        array $headers,
    ): bool
```

```text
Settle the record as completed and store the result future repeats are answered with.

The write must re-state the whole claim — same owner token, still in progress, lease not yet
lapsed — so a lost race answers false rather than overwriting a record another request now owns.
The implementation derives and stores the body's integrity digest beside it.

@param   string                 $subject     Principal the record is keyed against.
@param   string                 $operation   Method and path pair the key is scoped to.
@param   string                 $key         The client's `Idempotency-Key`.
@param   string                 $ownerToken  Token this request stored when it claimed the record.
@param   int                    $status      HTTP status a replay of this key must reproduce.
@param   string                 $body        Response body to store and replay verbatim.
@param   array<string, string>  $headers     Header lines a replay must reproduce, keyed by name.

@return  bool  True when exactly the one record this request owns was settled.

@since   0.1.0
```

### `release`

```php
public function release(string $subject, string $operation, string $key, string $ownerToken): bool
```

```text
Give the key back after an attempt that did not settle, deleting only this request's reservation.

The owner token and the in-progress state both condition the delete, so a record another attempt
has since taken over, or one that already completed, is untouched.

@param   string  $subject     Principal the record is keyed against.
@param   string  $operation   Method and path pair the key is scoped to.
@param   string  $key         The client's `Idempotency-Key`.
@param   string  $ownerToken  Token this request stored when it claimed the reservation.

@return  bool  True when exactly this request's record was deleted; false when it was no longer
         this request's to clear.

@since   0.1.0
```

## Kumwe\Idempotency\IdempotencyPurger

Source: [src/IdempotencyPurger.php](../src/IdempotencyPurger.php).

### `purgeExpired`

```php
public function purgeExpired(int $batchSize = 1_000): int
```

```text
Delete one bounded batch of expired, unowned idempotency records.

The count is the caller's continuation signal: a full batch means more expired records may
remain, while anything short of the batch size means the backlog is drained for now.

@param   int  $batchSize  Upper bound on how many records this call may remove.

@return  int  Number of records actually deleted, which is zero when nothing has expired.

@since   0.1.0
```

## Kumwe\Idempotency\IdempotencyRecord

Source: [src/IdempotencyRecord.php](../src/IdempotencyRecord.php).

### `begin`

```php
public static function begin(
        IdempotencyKey $key,
        string $subject,
        string $operation,
        mixed $request,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $expiresAt,
        CanonicalEncoder $encoder,
    ): self
```

```text
Claim a key for a request that has not run yet.

The request is fingerprinted here and then dropped, which is what lets a later replay be checked
against it without the ledger holding the payload for the length of the retention window.

@param   IdempotencyKey     $key        Caller-supplied token to claim.
@param   string             $subject    Principal claiming the key.
@param   string             $operation  Operation the key is being claimed for.
@param   mixed              $request    Request payload to fingerprint; must be representable as
         canonical JSON, so null, scalars and arrays of those, and nothing else.
@param   DateTimeImmutable  $createdAt  Instant the claim is made.
@param   DateTimeImmutable  $expiresAt  Instant from which the claim stops being replayable.

@return  self  A record in the IN_PROGRESS state, carrying no result yet.

@throws  InvalidArgumentException  When the subject or operation is empty once trimmed, the expiry is
         not after the creation instant, or the request holds a value canonical JSON cannot represent.

@since   0.1.0
```

### `fingerprintProfile`

```php
public function fingerprintProfile(): string
```

```text
Canonical profile bound to the request digest; persist beside requestDigest(). @since 0.1.0
```

### `createdAt`

```php
public function createdAt(): DateTimeImmutable
```

```text
Creation time retained across immutable transitions. @since 0.1.0
```

### `key`

```php
public function key(): IdempotencyKey
```

```text
Return the token this entry is filed under.

@return  IdempotencyKey  The already-validated key the claim was opened with.

@since   0.1.0
```

### `subject`

```php
public function subject(): string
```

```text
Return the principal the claim belongs to.

@return  string  Subject identifier; one half of the scope a key is only unique within.

@since   0.1.0
```

### `operation`

```php
public function operation(): string
```

```text
Return the operation the key was claimed for.

@return  string  Operation name; the other half of the scope a key is only unique within.

@since   0.1.0
```

### `requestDigest`

```php
public function requestDigest(): string
```

```text
Return the fingerprint of the request that opened the claim.

This is all the record keeps of that request, and it is what a later replay is checked against.

@return  string  Lowercase hexadecimal SHA-256, 64 characters wide.

@since   0.1.0
```

### `state`

```php
public function state(): IdempotencyState
```

```text
Report which stage of the lifecycle the entry has reached.

@return  IdempotencyState  COMPLETED is the only stage from which a result can be replayed.

@since   0.1.0
```

### `expiresAt`

```php
public function expiresAt(): DateTimeImmutable
```

```text
Return the instant the entry stops being replayable.

@return  DateTimeImmutable  Always strictly later than the instant the claim was opened.

@since   0.1.0
```

### `isExpiredAt`

```php
public function isExpiredAt(DateTimeImmutable $time): bool
```

```text
Decide whether the retention window has closed as of a given instant.

The boundary is inclusive: the entry counts as expired at exactly its expiry instant, not only
after it, so a purge and a replay reading the same clock never disagree about a borderline entry.

@param   DateTimeImmutable  $time  Instant to judge the entry against, normally the clock's reading.

@return  bool  True when the entry can no longer be replayed.

@since   0.1.0
```

### `assertRequestMatches`

```php
public function assertRequestMatches(mixed $request): void
```

```text
Confirm a request is the one this key was claimed for, and refuse it otherwise.

Both sides are reduced to canonical digests first, so two payloads differing only in the order
their keys were built in still match. The comparison itself runs in constant time, so a caller
cannot narrow down the stored digest by timing how far a mismatch got.

@param   mixed  $request  Request payload presented under the claimed key.

@return  void

@throws  DomainException  When the digests differ, meaning the key is being reused for a different
         request than the one it was claimed for.
@throws  InvalidArgumentException  When the request holds a value canonical JSON cannot represent.

@since   0.1.0
```

### `complete`

```php
public function complete(IdempotencyResult $result): self
```

```text
Close the claim successfully, attaching the answer a later replay will hand back.

@param   IdempotencyResult  $result  Status and body the operation produced.

@return  self  A new COMPLETED record carrying the result; the receiver is left untouched.

@throws  DomainException  When the claim is not open, because it has already completed or failed.

@since   0.1.0
```

### `fail`

```php
public function fail(): self
```

```text
Close the claim without an answer, after the operation did not produce one.

The entry is kept rather than discarded, so the key stays accounted for until it expires instead
of looking unclaimed to the next request that presents it.

@return  self  A new FAILED record carrying no result; the receiver is left untouched.

@throws  DomainException  When the claim is not open, because it has already completed or failed.

@since   0.1.0
```

### `replay`

```php
public function replay(mixed $request, DateTimeImmutable $time): IdempotencyResult
```

```text
Answer a repeat of the claimed request from the stored result.

Every condition that would make a replay wrong is checked before the result is released: the
request must fingerprint to the same value, the retention window must still be open, and the
operation must have completed. An open claim is refused rather than waited on, so a caller that
repeats a request while the first attempt is still running is told to come back instead of being
given a stale or absent answer.

@param   mixed              $request  Request payload presented under the claimed key.
@param   DateTimeImmutable  $time     Instant to judge the retention window against.

@return  IdempotencyResult  The result captured when the operation completed.

@throws  DomainException  When the request differs from the one claimed, the entry has expired, the
         operation is still in progress, or it ended without a result.
@throws  InvalidArgumentException  When the request holds a value canonical JSON cannot represent.

@since   0.1.0
```

## Kumwe\Idempotency\IdempotencyResult

Source: [src/IdempotencyResult.php](../src/IdempotencyResult.php).

### `__construct`

```php
public function __construct(private int $statusCode, array $body, CanonicalEncoder $encoder)
```

```text
Capture the status and body a finished operation answered with.

@param   int                   $statusCode  HTTP status the operation answered with.
@param   array<string, mixed>  $body        Response payload to replay, decoded rather than serialised.

@param CanonicalEncoder $encoder Explicit generic-v1 encoder; no fallback is selected.

@throws  InvalidArgumentException  When the status falls outside the 100 to 599 range, or the body holds
         a value canonical JSON cannot represent.

@since   0.1.0
```

### `fingerprintProfile`

```php
public function fingerprintProfile(): string
```

```text
Canonical profile bound to every stored response digest. @since 0.1.0
```

### `statusCode`

```php
public function statusCode(): int
```

```text
Return the status a replay should answer with.

@return  int  HTTP status code, between 100 and 599 inclusive.

@since   0.1.0
```

### `body`

```php
public function body(): array
```

```text
Return the payload a replay should answer with.

@return  array<string, mixed>  The payload exactly as captured; `bodyDigest()` fingerprints this
         same value, so a store can re-derive the digest and check what it persisted.

@since   0.1.0
```

### `bodyDigest`

```php
public function bodyDigest(): string
```

```text
Return the fingerprint a store compares a persisted body against.

@return  string  Lowercase hexadecimal SHA-256 of the body's canonical encoding, 64 characters wide.

@since   0.1.0
```

## Kumwe\Idempotency\IdempotencyState

Source: [src/IdempotencyState.php](../src/IdempotencyState.php).

## Kumwe\Idempotency\SecretOnceIdempotencyLedger

Source: [src/SecretOnceIdempotencyLedger.php](../src/SecretOnceIdempotencyLedger.php).

### `reserve`

```php
public function reserve(
        string $subject,
        string $operation,
        string $key,
        string $requestDigest,
        string $authorizationFingerprint,
        string $ownerToken,
    ): bool
```

```text
Reserve a key for this request by inserting an in-progress record, losing cleanly on a collision.

The implementation dates its short processing lease and the retention window from its own clock,
and must let the storage's uniqueness rule decide between simultaneous first attempts.

@param   string  $subject                   Principal the record is keyed against.
@param   string  $operation                 Method and path pair the key is scoped to.
@param   string  $key                       The client's validated `Idempotency-Key`.
@param   string  $requestDigest             Digest of the request the key is being spent on.
@param   string  $authorizationFingerprint  Credential and site fingerprint stored with the claim.
@param   string  $ownerToken                Random token marking the reservation as this request's.

@return  bool  True when the reservation is now this request's; false when a record for this
         subject, operation and key already existed.

@since   0.1.0
```

### `find`

```php
public function find(string $subject, string $operation, string $key): ?array
```

```text
Read the stored record a failed reservation collided with.

The projection is limited to what comparison and replay need; ownership is settled later, under a
lock, by `confirmLease()`.

@param   string  $subject    Principal the record is keyed against.
@param   string  $operation  Method and path pair the key is scoped to.
@param   string  $key        The client's `Idempotency-Key`.

@return  ?array<string, mixed>  The stored record keyed by column name — digests, state, result
         columns and expiry instants, each exactly as stored — or null when the record vanished
         between the collision and this read.

@since   0.1.0
```

### `takeOver`

```php
public function takeOver(
        string $subject,
        string $operation,
        string $key,
        string $requestDigest,
        string $authorizationFingerprint,
        string $ownerToken,
    ): bool
```

```text
Take over a record that failed, expired, or whose lease has lapsed, re-proving that in the write.

Everything the previous attempt left is wiped and the record is re-dated as a fresh reservation,
so a winner is indistinguishable from a first attempt. A record still live and owned is left
alone, which is what the false return reports.

@param   string  $subject                   Principal the record is keyed against.
@param   string  $operation                 Method and path pair the key is scoped to.
@param   string  $key                       The client's `Idempotency-Key`.
@param   string  $requestDigest             Digest of this request, replacing the stored one.
@param   string  $authorizationFingerprint  Fingerprint stored with the new reservation.
@param   string  $ownerToken                Token proving the new reservation is this request's.

@return  bool  True when this request now owns the record; false when it is still someone else's.

@since   0.1.0
```

### `confirmLease`

```php
public function confirmLease(
        string $subject,
        string $operation,
        string $key,
        string $ownerToken,
        string $authorizationFingerprint,
    ): bool
```

```text
Re-prove, under a storage-level lock, that this request still owns the reservation.

Called inside the caller's transaction so the lock holds until the completion write commits. The
implementation must compare the stored fingerprint in constant time, so a lease claimed under one
set of credentials cannot be spent under another.

@param   string  $subject                   Principal the record is keyed against.
@param   string  $operation                 Method and path pair the key is scoped to.
@param   string  $key                       The client's `Idempotency-Key`.
@param   string  $ownerToken                Token this request stored when it claimed the lease.
@param   string  $authorizationFingerprint  Fingerprint that must still match the stored one.

@return  bool  True when the record is present, in progress, this request's, fingerprint-matched
         and unlapsed; false on any other state.

@since   0.1.0
```

### `complete`

```php
public function complete(
        string $subject,
        string $operation,
        string $key,
        string $ownerToken,
        string $authorizationFingerprint,
        int $status,
        string $body,
        array $headers,
    ): bool
```

```text
Settle the record as completed and store the secret-free result future repeats are answered with.

The write must re-state the whole claim — owner token, fingerprint, in-progress state, unlapsed
lease — so a lost race answers false rather than storing a replay over a record another request
owns. The implementation derives and stores the body's integrity digest beside it.

@param   string                 $subject                   Principal the record is keyed against.
@param   string                 $operation                 Method and path pair the key is scoped to.
@param   string                 $key                       The client's `Idempotency-Key`.
@param   string                 $ownerToken                Token this request claimed the lease with.
@param   string                 $authorizationFingerprint  Fingerprint that must still match the row.
@param   int                    $status                    HTTP status a replay must reproduce.
@param   string                 $body                      Secret-free body to store and replay.
@param   array<string, string>  $headers                   Header lines to replay, keyed by name.

@return  bool  True when exactly the one record this request owns was settled.

@since   0.1.0
```

### `rewriteStoredResult`

```php
public function rewriteStoredResult(string $subject, string $operation, string $key, string $body): void
```

```text
Replace a stored result body whose legacy copy still carried the secret.

This is the second line of defence behind completion-time stripping: a record stored before that
stripping applied is made safe on its first replay instead of handing the secret out again. The
implementation re-derives the integrity digest for the replacement body.

@param   string  $subject    Principal the record is keyed against.
@param   string  $operation  Method and path pair the key is scoped to.
@param   string  $key        The client's `Idempotency-Key`.
@param   string  $body       Secret-free replacement body to store.

@return  void

@since   0.1.0
```

### `release`

```php
public function release(string $subject, string $operation, string $key, string $ownerToken): void
```

```text
Give up a reservation this request still holds, after the operation failed.

The record is deleted rather than marked failed, so the key is completely free for another
attempt. The owner token and the in-progress state both condition the delete, so a record another
request has since taken over, or one that already completed, is left untouched.

@param   string  $subject     Principal the record is keyed against.
@param   string  $operation   Method and path pair the key is scoped to.
@param   string  $key         The client's `Idempotency-Key`.
@param   string  $ownerToken  Token this request stored when it claimed the lease.

@return  void

@since   0.1.0
```

