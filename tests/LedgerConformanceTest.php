<?php

declare(strict_types=1);

namespace Kumwe\Idempotency\Tests;

use Kumwe\Idempotency\IdempotencyLedger;
use Kumwe\Idempotency\IdempotencyPurger;
use Kumwe\Idempotency\SecretOnceIdempotencyLedger;
use Kumwe\Idempotency\Tests\Conformance\LedgerContract;
use Kumwe\Idempotency\Tests\Fixture\LedgerStore;
use Kumwe\Idempotency\Tests\Fixture\SecretOnceLedger;

final class LedgerConformanceTest extends LedgerContract
{
    private LedgerStore $store;
    private SecretOnceLedger $secretLedger;

    protected function setUp(): void
    {
        $this->store = new LedgerStore();
        $this->secretLedger = new SecretOnceLedger($this->store);
    }

    protected function ledger(): IdempotencyLedger
    {
        return $this->store;
    }
    protected function secret(): SecretOnceIdempotencyLedger
    {
        return $this->secretLedger;
    }
    protected function purger(): IdempotencyPurger
    {
        return $this->store;
    }
    protected function at(int $seconds): void
    {
        $this->store->now = $seconds;
    }
    protected function seed(string $key, array $overrides): void
    {
        $this->store->reserve('actor', 'operation', $key, hash('sha256', 'request'), 'auth', 'owner');
        $id = $this->store->identity('actor', 'operation', $key);
        $this->store->rows[$id] = [...$this->store->rows[$id], ...$overrides];
    }
}
