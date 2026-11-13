<?php

declare(strict_types=1);

namespace Plugins\Comments\Domain\Events;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\DomainEventContract;
use Plugins\Comments\Domain\ValueObjects\CommentId;
use Plugins\Comments\Domain\ValueObjects\CommentStatus;

/**
 * Internal — a comment was created, whatever its initial status. The
 * cross-module announcement is {@see \Plugins\Comments\API\IntegrationEvents\CommentSubmittedIntegrationEvent},
 * dispatched by the service AFTER commit.
 */
final readonly class CommentSubmittedDomainEvent implements DomainEventContract
{
    public function __construct(
        public CommentId $commentId,
        public string $postId,
        public CommentStatus $status,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}
