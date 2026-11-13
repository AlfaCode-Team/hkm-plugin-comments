<?php

declare(strict_types=1);

namespace Plugins\Comments\Domain\ValueObjects;

/**
 * CommentId — the PUBLIC identifier (`comments.public_id`).
 *
 * A ULID: 26-char Crockford base32, lexicographically sortable in the same
 * order it was generated. The internal `id` bigint auto-increment never
 * leaves the persistence layer, so this is the only id a caller ever sees.
 *
 * Self-contained on purpose, same shape as `Plugins\Posts\Domain\ValueObjects\PostId`
 * and `Plugins\Feedback\Domain\ValueObjects\Ulid`: the kernel ships no ULID
 * type, and importing a sibling plugin's copy would couple this domain to a
 * plugin it has nothing to do with.
 */
final readonly class CommentId
{
    /** Crockford base32: no I, L, O or U — the characters people misread. */
    private const string ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public const int LENGTH = 26;

    private function __construct(private string $value)
    {
    }

    public static function generate(): self
    {
        $ms = (int) (microtime(true) * 1000);

        // 10 characters of timestamp (48 bits), 16 of randomness (80 bits).
        $time = '';
        for ($i = 9; $i >= 0; $i--) {
            $time = self::ALPHABET[$ms % 32] . $time;
            $ms = intdiv($ms, 32);
        }

        $random = '';
        for ($i = 0; $i < 16; $i++) {
            $random .= self::ALPHABET[random_int(0, 31)];
        }

        return new self($time . $random);
    }

    public static function fromString(string $value): self
    {
        $value = strtoupper(trim($value));

        if (strlen($value) !== self::LENGTH || strspn($value, self::ALPHABET) !== self::LENGTH) {
            throw new \DomainException('CommentId must be a 26-character Crockford base32 ULID.');
        }

        return new self($value);
    }

    /** True when $value is a syntactically valid id, without constructing one. */
    public static function isValid(string $value): bool
    {
        $value = strtoupper(trim($value));

        return strlen($value) === self::LENGTH && strspn($value, self::ALPHABET) === self::LENGTH;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
