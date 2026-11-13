<?php

declare(strict_types=1);

namespace Plugins\Comments\Domain\Entities;

use Plugins\Comments\Domain\Events\CommentApprovedDomainEvent;
use Plugins\Comments\Domain\Events\CommentSubmittedDomainEvent;
use Plugins\Comments\Domain\ValueObjects\AuthorEmail;
use Plugins\Comments\Domain\ValueObjects\AuthorUrl;
use Plugins\Comments\Domain\ValueObjects\CommentBody;
use Plugins\Comments\Domain\ValueObjects\CommentId;
use Plugins\Comments\Domain\ValueObjects\CommentStatus;

/**
 * Comment aggregate — one row of `comments`. Zero external imports.
 *
 * The thread position (`parentId`/`depth`) is resolved by the SERVICE before
 * the entity is built — walking the ancestor chain needs the repository,
 * which this layer may never import. The entity only enforces that `depth`
 * is never negative once it is told what it is.
 *
 * Status transitions this class owns:
 *   - `approve()` is idempotent: approving an already-approved comment
 *     records no event. Only a genuine pending→approved (or spam/trash→
 *     approved, for a moderator reinstating one) transition does.
 *   - `markSpam()` / `markTrash()` / `restore()` just set the column; they are
 *     plain moderation actions with no event of their own (approve is the one
 *     transition other modules care about).
 */
final class Comment
{
    /** @var list<\AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\DomainEventContract> */
    private array $domainEvents = [];

    private function __construct(
        private readonly CommentId $id,
        private readonly string $postId,
        private ?string $parentId,
        private int $depth,
        private ?string $authorUserId,
        private string $authorName,
        private AuthorEmail $authorEmail,
        private ?AuthorUrl $authorUrl,
        private CommentBody $body,
        private CommentStatus $status,
        private readonly string $ipHash,
        private readonly ?string $userAgent,
        private readonly \DateTimeImmutable $createdAt,
        private \DateTimeImmutable $updatedAt,
        private ?\DateTimeImmutable $approvedAt,
    ) {
    }

    public static function submit(
        string $postId,
        ?string $parentId,
        int $depth,
        ?string $authorUserId,
        string $authorName,
        AuthorEmail $authorEmail,
        ?AuthorUrl $authorUrl,
        CommentBody $body,
        CommentStatus $status,
        string $ipHash,
        ?string $userAgent,
        \DateTimeImmutable $now,
    ): self {
        $comment = new self(
            id: CommentId::generate(),
            postId: $postId,
            parentId: $parentId,
            depth: max(0, $depth),
            authorUserId: $authorUserId,
            authorName: $authorName,
            authorEmail: $authorEmail,
            authorUrl: $authorUrl,
            body: $body,
            status: $status,
            ipHash: $ipHash,
            userAgent: $userAgent,
            createdAt: $now,
            updatedAt: $now,
            approvedAt: $status === CommentStatus::Approved ? $now : null,
        );

        $comment->domainEvents[] = new CommentSubmittedDomainEvent($comment->id, $postId, $status, $now);

        if ($status === CommentStatus::Approved) {
            $comment->domainEvents[] = new CommentApprovedDomainEvent($comment->id, $postId, $now);
        }

        return $comment;
    }

    /**
     * Rehydrate a stored row. `$row` keys are the raw `comments` columns.
     *
     * @param array<string, mixed> $row
     */
    public static function reconstitute(array $row): self
    {
        return new self(
            id: CommentId::fromString((string) $row['public_id']),
            postId: (string) $row['post_id'],
            parentId: self::nullableString($row['parent_id'] ?? null),
            depth: (int) $row['depth'],
            authorUserId: self::nullableString($row['author_user_id'] ?? null),
            authorName: (string) $row['author_name'],
            authorEmail: AuthorEmail::fromString((string) $row['author_email']),
            authorUrl: self::nullableString($row['author_url'] ?? null) !== null
                ? AuthorUrl::fromString((string) $row['author_url'])
                : null,
            body: CommentBody::fromString((string) $row['body']),
            status: CommentStatus::fromString((string) $row['status']),
            ipHash: (string) $row['ip_hash'],
            userAgent: self::nullableString($row['user_agent'] ?? null),
            createdAt: self::parseStoredInstant($row['created_at']) ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            updatedAt: self::parseStoredInstant($row['updated_at']) ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            approvedAt: self::parseStoredInstant($row['approved_at'] ?? null),
        );
    }

    /** Moves to Approved. No-op (no event) when already approved. */
    public function approve(\DateTimeImmutable $now): void
    {
        if ($this->status === CommentStatus::Approved) {
            return;
        }

        $this->status     = CommentStatus::Approved;
        $this->approvedAt = $now;
        $this->updatedAt  = $now;

        $this->domainEvents[] = new CommentApprovedDomainEvent($this->id, $this->postId, $now);
    }

    public function markSpam(\DateTimeImmutable $now): void
    {
        $this->status    = CommentStatus::Spam;
        $this->updatedAt = $now;
    }

    public function markTrash(\DateTimeImmutable $now): void
    {
        $this->status    = CommentStatus::Trash;
        $this->updatedAt = $now;
    }

    public function moveTo(CommentStatus $status, \DateTimeImmutable $now): void
    {
        if ($status === CommentStatus::Approved) {
            $this->approve($now);

            return;
        }

        $this->status    = $status;
        $this->updatedAt = $now;
    }

    public function editBody(CommentBody $body, \DateTimeImmutable $now): void
    {
        $this->body      = $body;
        $this->updatedAt = $now;
    }

    /** @return list<\AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\DomainEventContract> */
    public function releaseEvents(): array
    {
        $events             = $this->domainEvents;
        $this->domainEvents = [];

        return $events;
    }

    public function id(): CommentId
    {
        return $this->id;
    }

    public function postId(): string
    {
        return $this->postId;
    }

    public function parentId(): ?string
    {
        return $this->parentId;
    }

    public function depth(): int
    {
        return $this->depth;
    }

    public function authorUserId(): ?string
    {
        return $this->authorUserId;
    }

    public function authorName(): string
    {
        return $this->authorName;
    }

    public function authorEmail(): AuthorEmail
    {
        return $this->authorEmail;
    }

    public function authorUrl(): ?AuthorUrl
    {
        return $this->authorUrl;
    }

    public function body(): CommentBody
    {
        return $this->body;
    }

    public function status(): CommentStatus
    {
        return $this->status;
    }

    public function ipHash(): string
    {
        return $this->ipHash;
    }

    public function userAgent(): ?string
    {
        return $this->userAgent;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function approvedAt(): ?\DateTimeImmutable
    {
        return $this->approvedAt;
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    private static function parseStoredInstant(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC'));
    }
}
