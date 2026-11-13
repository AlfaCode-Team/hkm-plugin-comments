<?php

declare(strict_types=1);

namespace Plugins\Comments\Domain\ValueObjects;

/**
 * AuthorEmail — stored for moderation (the "first" policy keys on it) and
 * shown to admins, but NEVER exposed in a public view. Normalised lower-case
 * so "Jane@Example.com" and "jane@example.com" are the same commenter for
 * trust and duplicate-detection purposes.
 */
final readonly class AuthorEmail
{
    public const int MAX_LENGTH = 190;

    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $value = strtolower(trim($value));

        if ($value === '' || mb_strlen($value) > self::MAX_LENGTH || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new \DomainException('Please provide a valid email address.');
        }

        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }
}
