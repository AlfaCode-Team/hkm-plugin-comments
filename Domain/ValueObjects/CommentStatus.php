<?php

declare(strict_types=1);

namespace Plugins\Comments\Domain\ValueObjects;

/**
 * CommentStatus — unlike `PostStatus` in the sibling Posts plugin, this one IS
 * a stored column. A comment's visibility is a moderation decision an editor
 * makes (or the spam guard makes for them), not something derivable from a
 * date — so there is no "resolve from timestamps" function here.
 */
enum CommentStatus: string
{
    case Pending  = 'pending';
    case Approved = 'approved';
    case Spam     = 'spam';
    case Trash    = 'trash';

    public static function fromString(string $value): self
    {
        return self::tryFrom($value)
            ?? throw new \DomainException("Unknown comment status [{$value}].");
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn(self $case): string => $case->value, self::cases());
    }
}
