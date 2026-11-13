<?php

declare(strict_types=1);

namespace Plugins\Comments\Domain\ValueObjects;

/**
 * AuthorUrl — optional "website" field on a comment. http(s) only: anything
 * else (javascript:, data:, a bare domain with no scheme) is rejected rather
 * than silently stripped, so a commenter sees why their link did not save.
 */
final readonly class AuthorUrl
{
    public const int MAX_LENGTH = 500;

    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $value = trim($value);

        if (mb_strlen($value) > self::MAX_LENGTH || !preg_match('#^https?://[^\s]+$#i', $value)) {
            throw new \DomainException('The website URL must start with http:// or https://.');
        }

        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }
}
