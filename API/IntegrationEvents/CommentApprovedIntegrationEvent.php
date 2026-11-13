<?php

declare(strict_types=1);

namespace Plugins\Comments\API\IntegrationEvents;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;

/**
 * Cross-module announcement that a comment became visible — either
 * auto-approved at submission (moderation policy, or a moderator posting)
 * or approved later via {@see \Plugins\Comments\API\Contracts\CommentServiceContract::moderate()}
 * / {@see \Plugins\Comments\API\Contracts\CommentServiceContract::bulkModerate()}.
 * Dispatched at most once per comment per approval (re-approving an already
 * approved comment is a no-op in the domain and emits nothing).
 */
final readonly class CommentApprovedIntegrationEvent implements IntegrationEventContract
{
    public string $version;

    public function __construct(
        public string $commentId,
        public string $postId,
        public string $occurredAt,
    ) {
        $this->version = '1.0';
    }

    public function name(): string
    {
        return 'comment.approved';
    }

    public function version(): string
    {
        return $this->version;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'commentId'  => $this->commentId,
            'postId'     => $this->postId,
            'occurredAt' => $this->occurredAt,
            'version'    => $this->version,
        ];
    }
}
