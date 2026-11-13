<?php

declare(strict_types=1);

namespace Plugins\Comments\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\DomainEventCollector;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\SecurityException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Comments\API\Contracts\CommentServiceContract;
use Plugins\Comments\API\DTO\CommentAdminPage;
use Plugins\Comments\API\DTO\CommentAdminView;
use Plugins\Comments\API\DTO\CommentPage;
use Plugins\Comments\API\DTO\CommentView;
use Plugins\Comments\API\IntegrationEvents\CommentApprovedIntegrationEvent;
use Plugins\Comments\API\IntegrationEvents\CommentSubmittedIntegrationEvent;
use Plugins\Comments\Application\Spam\FormToken;
use Plugins\Comments\Application\Spam\SpamGuard;
use Plugins\Comments\Domain\Entities\Comment;
use Plugins\Comments\Domain\ValueObjects\AuthorEmail;
use Plugins\Comments\Domain\ValueObjects\AuthorUrl;
use Plugins\Comments\Domain\ValueObjects\CommentBody;
use Plugins\Comments\Domain\ValueObjects\CommentId;
use Plugins\Comments\Domain\ValueObjects\CommentStatus;
use Plugins\Comments\Infrastructure\Persistence\CommentCriteria;
use Plugins\Comments\Infrastructure\Persistence\CommentRepository;
use Plugins\Comments\Support\Messages;
use Plugins\Posts\API\Contracts\PostServiceContract;

/**
 * CommentService — orchestrates reader comments on a Posts plugin post.
 *
 * Security posture: this plugin's admin methods (`moderate`, `update`,
 * `delete`, `bulkModerate`) take `$actorId` rather than an `Identity` — the
 * `comments.moderate` ROUTE FILTER is where 401/403 is decided (see
 * {@see \Plugins\Comments\Infrastructure\Http\Stages\CommentModerateStage}),
 * so a CLI tool or another module can call these without an HTTP request in
 * flight. `submit()` DOES receive an optional Identity: a comment may be
 * posted by a guest, and the identity (when present) only ever RAISES trust
 * (auto-approve, default author fields) — it is never required.
 *
 * Spam guard order in `submit()`:
 *   1. the post must exist, be published, and accept comments
 *   2. field format (name/email/url/body) — ALL errors collected at once
 *   3. honeypot — silent: marks the eventual status Spam, never rejects
 *   4. form-token timing (too fast / stale) — rejects
 *   5. thread position — rejects an unknown parent
 *   6. duplicate detection — rejects
 *   7. per-IP rate limit (CachePort, best-effort) — rejects
 *   8. max links — marks the eventual status Spam, never rejects
 *   9. moderation policy decides Pending vs Approved for whatever is left
 *
 * Integration events are built directly from the persisted aggregate AFTER
 * commit, never from inside the try block, and never from the
 * DomainEventCollector's buffer — the collector exists to protect against
 * PHANTOM events on rollback (discard()), not as the source the integration
 * event is translated from.
 */
final class CommentService implements CommentServiceContract
{
    /** However stale a form token may be before it is refused outright. */
    private const int TOKEN_MAX_AGE_SECONDS = 86400;

    /** Same post + email + body within this many minutes is a duplicate. */
    private const int DUPLICATE_WINDOW_MINUTES = 10;

    /** Most comments one bulkModerate() call may touch. */
    public const int MAX_BULK = 200;

    public function __construct(
        private readonly CommentRepository $repository,
        private readonly TransactionManager $transaction,
        private readonly DomainEventCollector $collector,
        private readonly EventBus $eventBus,
        private readonly ClockPort $clock,
        private readonly PostServiceContract $posts,
        private readonly FormToken $formToken,
        private readonly ?CachePort $cache,
        /** 'all' | 'first' | 'none' — see README for the behaviour of each. */
        private readonly string $moderationPolicy,
        private readonly string $moderatePermission,
        private readonly int $minSeconds,
        private readonly int $maxLinks,
        private readonly int $maxDepth,
        private readonly int $ratePerMinute,
        private readonly string $ipSalt,
    ) {
    }

    public function formToken(): string
    {
        return $this->formToken->mint($this->clock->timestamp());
    }

    public function submit(
        string $postId,
        array $input,
        ?Identity $identity,
        string $ip,
        ?string $userAgent,
    ): CommentView {
        $now = $this->clock->now();

        $post = $this->posts->find($postId);
        if ($post === null || $post->status !== 'published' || !$post->commentsEnabled) {
            throw new ValidationException(['postId' => Messages::get('post.not_accepting', 'This post is not accepting comments.')]);
        }

        [$authorName, $authorEmail, $authorUrl, $body, $errors] = $this->validateFields($input, $identity);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $isHoneypot = SpamGuard::honeypotTriggered((string) ($input['website_url'] ?? ''));

        $issuedAt = $this->formToken->verify((string) ($input['startedAt'] ?? ''));
        $nowTs    = $now->getTimestamp();
        if ($issuedAt === null || SpamGuard::formTokenExpired($issuedAt, $nowTs, self::TOKEN_MAX_AGE_SECONDS)) {
            throw new ValidationException(['startedAt' => Messages::get('form_token.expired', 'This form has expired. Please reload the page and try again.')]);
        }
        if (SpamGuard::submittedTooFast($issuedAt, $nowTs, $this->minSeconds)) {
            throw new ValidationException(['startedAt' => Messages::get('form_token.too_fast', 'Please take a moment before submitting.')]);
        }

        [$parentId, $depth] = $this->resolveThreadPosition($postId, isset($input['parentId']) ? (string) $input['parentId'] : null);

        $since = $now->modify('-' . self::DUPLICATE_WINDOW_MINUTES . ' minutes');
        if ($this->repository->hasRecentDuplicate($postId, $authorEmail->value(), $body->value(), $since)) {
            throw new ValidationException(['body' => Messages::get('body.duplicate', 'You have already submitted this comment.')]);
        }

        $this->assertWithinRateLimit($ip);

        $status = $this->resolveStatus($isHoneypot, $body, $identity, $authorEmail);
        $ipHash = hash('sha256', $ip . $this->ipSalt);

        $this->collector->beginCollection();
        $this->transaction->begin();
        try {
            $comment = Comment::submit(
                postId: $postId,
                parentId: $parentId,
                depth: $depth,
                authorUserId: ($identity !== null && !$identity->isGuest()) ? $identity->userId : null,
                authorName: $authorName,
                authorEmail: $authorEmail,
                authorUrl: $authorUrl,
                body: $body,
                status: $status,
                ipHash: $ipHash,
                userAgent: $userAgent === null ? null : mb_substr($userAgent, 0, 255),
                now: $now,
            );

            foreach ($comment->releaseEvents() as $event) {
                $this->collector->collect($event);
            }

            $this->repository->insert($comment);
            $this->transaction->commit();
        } catch (\Throwable $e) {
            $this->transaction->rollback();
            $this->collector->discard();
            throw $this->wrap($e, 'comment.submit.failed');
        }

        $this->collector->release();

        $this->eventBus->dispatch(new CommentSubmittedIntegrationEvent(
            commentId: $comment->id()->value(),
            postId: $comment->postId(),
            status: $comment->status()->value,
            occurredAt: $comment->createdAt()->format(\DateTimeInterface::RFC3339),
        ));

        if ($comment->status() === CommentStatus::Approved) {
            $this->eventBus->dispatch(new CommentApprovedIntegrationEvent(
                commentId: $comment->id()->value(),
                postId: $comment->postId(),
                occurredAt: ($comment->approvedAt() ?? $now)->format(\DateTimeInterface::RFC3339),
            ));
        }

        return $this->toView($comment);
    }

    public function thread(string $postId, int $page = 1, int $perPage = 20): CommentPage
    {
        $page    = max(1, $page);
        $perPage = max(1, min($perPage, CommentRepository::MAX_PER_PAGE));
        $offset  = ($page - 1) * $perPage;

        $total    = $this->repository->countApprovedTopLevel($postId);
        $topLevel = $this->repository->threadTopLevel($postId, $perPage, $offset);
        $replies  = $this->repository->threadReplies($postId);

        $byParent = [];
        foreach ($replies as $reply) {
            $byParent[(string) $reply->parentId()][] = $reply;
        }

        $items = array_map(fn(Comment $c): CommentView => $this->toView($c, $byParent), $topLevel);

        return new CommentPage($items, $total, $page, $perPage);
    }

    public function count(string $postId): int
    {
        return $this->repository->countApproved($postId);
    }

    public function counts(array $postIds): array
    {
        return $this->repository->countsApproved($postIds);
    }

    public function list(
        ?string $status = null,
        ?string $postId = null,
        ?string $search = null,
        int $page = 1,
        int $perPage = 20,
    ): CommentAdminPage {
        $statusVO = $status === null ? null : $this->statusFromString($status);

        $page    = max(1, $page);
        $perPage = max(1, min($perPage, CommentRepository::MAX_PER_PAGE));
        $offset  = ($page - 1) * $perPage;

        $criteria = new CommentCriteria($statusVO, $postId, $search);
        $total    = $this->repository->count($criteria);
        $rows     = $this->repository->search($criteria, $perPage, $offset);

        return new CommentAdminPage(array_map($this->toAdminView(...), $rows), $total, $page, $perPage);
    }

    public function find(string $id): ?CommentAdminView
    {
        $comment = $this->repository->findByPublicId($this->normalizeId($id));

        return $comment === null ? null : $this->toAdminView($comment);
    }

    public function moderate(string $id, string $status, string $actorId): ?CommentAdminView
    {
        $target = $this->statusFromString($status);

        $comment = $this->repository->findByPublicId($this->normalizeId($id));
        if ($comment === null) {
            return null;
        }

        $now         = $this->clock->now();
        $wasApproved = $comment->status() === CommentStatus::Approved;

        $this->collector->beginCollection();
        $this->transaction->begin();
        try {
            $comment->moveTo($target, $now);

            foreach ($comment->releaseEvents() as $event) {
                $this->collector->collect($event);
            }

            $this->repository->update($comment);
            $this->transaction->commit();
        } catch (\Throwable $e) {
            $this->transaction->rollback();
            $this->collector->discard();
            throw $this->wrap($e, 'comment.moderate.failed', ['id' => $id]);
        }

        $this->collector->release();

        if (!$wasApproved && $comment->status() === CommentStatus::Approved) {
            $this->eventBus->dispatch(new CommentApprovedIntegrationEvent(
                commentId: $comment->id()->value(),
                postId: $comment->postId(),
                occurredAt: ($comment->approvedAt() ?? $now)->format(\DateTimeInterface::RFC3339),
            ));
        }

        return $this->toAdminView($comment);
    }

    public function update(string $id, string $body, string $actorId): ?CommentAdminView
    {
        try {
            $bodyVO = CommentBody::fromString($body);
        } catch (\DomainException $e) {
            throw new ValidationException(['body' => $e->getMessage()]);
        }

        $comment = $this->repository->findByPublicId($this->normalizeId($id));
        if ($comment === null) {
            return null;
        }

        $now = $this->clock->now();

        $this->collector->beginCollection();
        $this->transaction->begin();
        try {
            $comment->editBody($bodyVO, $now);

            foreach ($comment->releaseEvents() as $event) {
                $this->collector->collect($event);
            }

            $this->repository->update($comment);
            $this->transaction->commit();
        } catch (\Throwable $e) {
            $this->transaction->rollback();
            $this->collector->discard();
            throw $this->wrap($e, 'comment.update.failed', ['id' => $id]);
        }

        $this->collector->release();

        return $this->toAdminView($comment);
    }

    public function delete(string $id): bool
    {
        $normalized = $this->normalizeId($id);

        // Nothing here raises a domain event today; the collector frames the
        // transaction anyway so one added later cannot outlive a rollback.
        $this->collector->beginCollection();
        $this->transaction->begin();
        try {
            $deleted = $this->repository->delete($normalized);
            $this->transaction->commit();
        } catch (\Throwable $e) {
            $this->transaction->rollback();
            $this->collector->discard();
            throw $this->wrap($e, 'comment.delete.failed', ['id' => $id]);
        }

        $this->collector->release();

        return $deleted;
    }

    public function bulkModerate(array $ids, string $status, string $actorId): int
    {
        $target = $this->statusFromString($status);

        // A non-scalar entry is not an id; skip it rather than fail on a cast.
        $normalizedIds = array_values(array_unique(array_map(
            fn(int|float|string|bool $id): string => $this->normalizeId((string) $id),
            array_filter($ids, 'is_scalar'),
        )));
        if ($normalizedIds === []) {
            return 0;
        }
        // Every id is a bound placeholder; past a few thousand the driver's
        // own limit fails the statement with a 500. Same cap as Posts' bulk.
        if (\count($normalizedIds) > self::MAX_BULK) {
            throw new ValidationException(['ids' => Messages::get('bulk.too_many', 'At most :max comments at a time.', ['max' => (string) self::MAX_BULK])]);
        }

        $now        = $this->clock->now();
        $approvedAt = $target === CommentStatus::Approved ? $now : null;

        $this->collector->beginCollection();
        $this->transaction->begin();
        try {
            // The PREVIOUS state, read in ONE query inside the same
            // transaction as the write, so an approval is announced only for
            // rows that were not already approved.
            $before  = $target === CommentStatus::Approved ? $this->repository->findManyByPublicIds($normalizedIds) : [];
            $changed = $this->repository->bulkUpdateStatus($normalizedIds, $target, $approvedAt, $now);
            $this->transaction->commit();
        } catch (\Throwable $e) {
            $this->transaction->rollback();
            $this->collector->discard();
            throw $this->wrap($e, 'comment.bulk_moderate.failed');
        }

        $this->collector->release();

        if ($target === CommentStatus::Approved) {
            foreach ($before as $pid => $existing) {
                if ($existing->status() !== CommentStatus::Approved) {
                    $this->eventBus->dispatch(new CommentApprovedIntegrationEvent(
                        commentId: $pid,
                        postId: $existing->postId(),
                        occurredAt: $now->format(\DateTimeInterface::RFC3339),
                    ));
                }
            }
        }

        return $changed;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: string, 1: AuthorEmail, 2: ?AuthorUrl, 3: CommentBody, 4: array<string, string>}
     */
    private function validateFields(array $input, ?Identity $identity): array
    {
        $errors = [];
        $loggedIn = $identity !== null && !$identity->isGuest();

        $authorName = trim((string) ($input['authorName'] ?? ($loggedIn ? ($identity->fullName !== '' ? $identity->fullName : $identity->username) : '')));
        if ($authorName === '') {
            $errors['authorName'] = Messages::get('author_name.required', 'Please enter your name.');
        } elseif (mb_strlen($authorName) > 120) {
            $errors['authorName'] = Messages::get('author_name.too_long', 'Your name is too long.');
        }

        $authorEmail = null;
        $emailInput  = trim((string) ($input['authorEmail'] ?? ($loggedIn ? $identity->email : '')));
        try {
            $authorEmail = AuthorEmail::fromString($emailInput);
        } catch (\DomainException $e) {
            $errors['authorEmail'] = Messages::get('author_email.invalid', $e->getMessage());
        }

        $authorUrl = null;
        $urlInput  = trim((string) ($input['authorUrl'] ?? ''));
        if ($urlInput !== '') {
            try {
                $authorUrl = AuthorUrl::fromString($urlInput);
            } catch (\DomainException $e) {
                $errors['authorUrl'] = Messages::get('author_url.invalid', $e->getMessage());
            }
        }

        $body = null;
        try {
            $body = CommentBody::fromString((string) ($input['body'] ?? ''));
        } catch (\DomainException $e) {
            $errors['body'] = Messages::get('comment_body.invalid', $e->getMessage());
        }

        return [
            $authorName,
            $authorEmail ?? AuthorEmail::fromString('placeholder@invalid.example'),
            $authorUrl,
            $body ?? CommentBody::fromString('placeholder'),
            $errors,
        ];
    }

    /**
     * Clamp the requested parent to {@see $maxDepth}: a reply to a comment
     * already at the maximum nests no further — it attaches to that
     * comment's OWN parent instead (the deepest allowed ancestor), at the
     * same maximum depth, rather than being rejected or silently flattened
     * to a top-level comment.
     *
     * @return array{0: ?string, 1: int}
     */
    private function resolveThreadPosition(string $postId, ?string $parentId): array
    {
        if ($parentId === null || $parentId === '') {
            return [null, 0];
        }

        if (!CommentId::isValid($parentId)) {
            throw new ValidationException(['parentId' => Messages::get('parent.not_found', 'That comment could not be found.')]);
        }

        $parent = $this->repository->findByPublicId($this->normalizeId($parentId));
        if ($parent === null || $parent->postId() !== $postId) {
            throw new ValidationException(['parentId' => Messages::get('parent.not_found', 'That comment could not be found.')]);
        }

        if ($parent->depth() < $this->maxDepth) {
            return [$parent->id()->value(), $parent->depth() + 1];
        }

        return [$parent->parentId(), $this->maxDepth];
    }

    private function resolveStatus(bool $isHoneypot, CommentBody $body, ?Identity $identity, AuthorEmail $authorEmail): CommentStatus
    {
        if ($isHoneypot) {
            return CommentStatus::Spam;
        }

        if (SpamGuard::exceedsMaxLinks($body->value(), $this->maxLinks)) {
            return CommentStatus::Spam;
        }

        if ($identity !== null && !$identity->isGuest() && $identity->hasPermission($this->moderatePermission)) {
            return CommentStatus::Approved;
        }

        return match ($this->moderationPolicy) {
            'none' => CommentStatus::Approved,
            'all'  => CommentStatus::Pending,
            // 'first': trust a commenter once they have one approved comment
            // ANYWHERE on this installation — not scoped to this one post.
            default => $this->repository->hasApprovedByEmail($authorEmail->value()) ? CommentStatus::Approved : CommentStatus::Pending,
        };
    }

    private function assertWithinRateLimit(string $ip): void
    {
        if ($this->cache === null || $this->ratePerMinute <= 0) {
            return;
        }

        $window = intdiv($this->clock->timestamp(), 60);
        $key    = 'comments:rl:' . hash('sha256', $ip) . ':' . $window;

        try {
            if (!$this->cache->has($key)) {
                $this->cache->set($key, 0, 120);
            }
            $count = $this->cache->increment($key);
        } catch (\Throwable) {
            return; // best-effort — a cache outage must not block legitimate comments
        }

        if ($count > $this->ratePerMinute) {
            // 429, not 422: the input is fine, the sender is too fast. The
            // kernel's ErrorStage honours a SecurityException's 429 code.
            throw new SecurityException('comment.submit.rate_limited', layer: 'service.comments', context: [
                'limit' => $this->ratePerMinute,
            ], code: 429);
        }
    }

    private function statusFromString(string $status): CommentStatus
    {
        return CommentStatus::tryFrom($status) ?? throw new ValidationException(['status' => Messages::get('status.invalid', 'That is not a valid comment status.')]);
    }

    private function normalizeId(string $id): string
    {
        return strtoupper(trim($id));
    }

    /** @param array<string, list<Comment>> $byParent public_id => its direct replies, oldest first. */
    private function toView(Comment $comment, array $byParent = []): CommentView
    {
        $children = $byParent[$comment->id()->value()] ?? [];
        $replies  = array_map(fn(Comment $c): CommentView => $this->toView($c, $byParent), $children);

        return new CommentView(
            id: $comment->id()->value(),
            postId: $comment->postId(),
            parentId: $comment->parentId(),
            depth: $comment->depth(),
            authorName: $comment->authorName(),
            authorUrl: $comment->authorUrl()?->value(),
            body: htmlspecialchars($comment->body()->value(), ENT_QUOTES, 'UTF-8'),
            status: $comment->status()->value,
            createdAt: $comment->createdAt()->format(\DateTimeInterface::ATOM),
            replies: $replies,
        );
    }

    private function toAdminView(Comment $comment): CommentAdminView
    {
        return new CommentAdminView(
            id: $comment->id()->value(),
            postId: $comment->postId(),
            parentId: $comment->parentId(),
            depth: $comment->depth(),
            authorUserId: $comment->authorUserId(),
            authorName: $comment->authorName(),
            authorEmail: $comment->authorEmail()->value(),
            authorUrl: $comment->authorUrl()?->value(),
            body: $comment->body()->value(),
            status: $comment->status()->value,
            ipHash: $comment->ipHash(),
            userAgent: $comment->userAgent(),
            createdAt: $comment->createdAt()->format(\DateTimeInterface::ATOM),
            updatedAt: $comment->updatedAt()->format(\DateTimeInterface::ATOM),
            approvedAt: $comment->approvedAt()?->format(\DateTimeInterface::ATOM),
        );
    }

    private function wrap(\Throwable $e, string $code, array $context = []): \Throwable
    {
        if ($e instanceof ServiceException
            || $e instanceof ValidationException
            || $e instanceof \AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\DomainException
        ) {
            return $e;
        }

        return new ServiceException($code, layer: 'service.comments', context: $context, previous: $e);
    }
}
