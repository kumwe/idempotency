<?php

declare(strict_types=1);

namespace Kumwe\Idempotency;

use InvalidArgumentException;
use Stringable;

/** Validated caller-supplied identity of one replay-protected operation. @since 0.1.0 */
final readonly class IdempotencyKey implements Stringable
{
    /** @param string $key Already-validated transport-safe replay identity being frozen. @since 0.1.0 */
    private function __construct(private string $key)
    {
    }

    /**
     * @param   string  $value  Caller-supplied replay identity of one replay-protected operation.
     *
     * @throws  InvalidArgumentException  When the value is not 8 to 128 transport-safe ASCII characters.
     *
     * @since   0.1.0
     */
    public static function fromString(string $value): self
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/D', $value) !== 1) {
            throw new InvalidArgumentException(
                'An idempotency key must contain 8 to 128 transport-safe ASCII characters.',
            );
        }

        return new self($value);
    }

    /**
     * Parse a transport header using the same key grammar after trimming whitespace.
     *
     * @param string $value Header value as received.
     * @return self Validated canonical key.
     * @throws InvalidArgumentException When the trimmed value violates the key grammar.
     * @since 0.1.0
     */
    public static function fromHeader(string $value): self
    {
        return self::fromString(trim($value));
    }
    /** @return string 8 to 128 transport-safe ASCII characters. @since 0.1.0 */
    public function value(): string
    {
        return $this->key;
    }

    /** Compare replay identities in constant time. @param self $other Key claimed by another request. @since 0.1.0 */
    public function equals(self $other): bool
    {
        return hash_equals($this->key, $other->key);
    }

    /** @since 0.1.0 */
    public function __toString(): string
    {
        return $this->key;
    }
}
