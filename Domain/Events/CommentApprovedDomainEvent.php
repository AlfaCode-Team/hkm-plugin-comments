<?php

declare(strict_types=1);

namespace Plugins\Comments\Domain\Events;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\DomainEventContract;
use Plugins\Comments\Domain\ValueObjects\CommentId;

/**
 * Internal — a comment transitioned INTO the approved status, whether that
 * happened at submission time (auto-approve) or later via moderation. The
 * entity records this at most once per transition (approving an
 * already-approved comment is a no-op and records nothing).
 */
final readonly class CommentApprovedDomainEvent implements DomainEventContract
{
    public function __construct(
        public CommentId $commentId,
        public string $postId,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}
