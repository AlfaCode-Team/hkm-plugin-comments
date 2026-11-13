<?php

declare(strict_types=1);

namespace Plugins\Comments\Domain\ValueObjects;

/**
 * CommentBody — plain text, stored verbatim. This plugin accepts no HTML: a
 * comment is text, never markdown or rich content. Rendering decisions (if
 * any — e.g. auto-linking) belong to the host, same boundary rule as the
 * Posts plugin's `PostBody`.
 */
final readonly class CommentBody
{
    public const int MAX_LENGTH = 5000;

    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw new \DomainException('A comment may not be empty.');
        }

        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            throw new \DomainException('A comment may be at most ' . self::MAX_LENGTH . ' characters.');
        }

        return new self($trimmed);
    }

    public function value(): string
    {
        return $this->value;
    }

    /** Links in the body — "http://" or "https://", case-insensitive. */
    public function linkCount(): int
    {
        return (int) preg_match_all('#https?://#i', $this->value);
    }
}
