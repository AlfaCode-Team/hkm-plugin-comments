<?php

declare(strict_types=1);

namespace Plugins\Comments\API\Contracts;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Comments\API\DTO\CommentAdminPage;
use Plugins\Comments\API\DTO\CommentAdminView;
use Plugins\Comments\API\DTO\CommentPage;
use Plugins\Comments\API\DTO\CommentView;

/**
 * Published contract for `comment.discussion` — reader comments on a
 * `Plugins\Posts` post.
 *
 * This plugin declares its OWN routes (unlike Posts, which declares none) and
 * performs its OWN authorization for the admin half of this contract via the
 * `comments.moderate` route filter; `moderate()` / `update()` / `delete()` /
 * `bulkModerate()` still take `$actorId` explicitly rather than reading an
 * Identity themselves, so a CLI import tool or another module can call them
 * without needing an HTTP request in flight.
 *
 * Two families of read, same split as PostServiceContract:
 *
 *   PUBLIC  `thread()` / `count()` / `counts()` — approved comments only.
 *   ADMIN   `list()` / `find()` — any status, for a moderation queue.
 */
interface CommentServiceContract
{
    /**
     * A signed "this form was rendered now" token the client echoes back as
     * `startedAt` on submit — see {@see self::submit()}.
     */
    public function formToken(): string;

    /**
     * Submit a comment on a post.
     *
     * `$identity` is OPTIONAL: comments may be posted by a guest. When
     * present and not a guest, `authorUserId` is taken from it and
     * `authorName`/`authorEmail` default to its `fullName`/`email` when the
     * input omits them.
     *
     * Input keys:
     *   - `parentId`     (optional) public id of the comment being replied to.
     *   - `authorName`   required unless the identity supplies one.
     *   - `authorEmail`  required unless the identity supplies one.
     *   - `authorUrl`    optional, http(s) only.
     *   - `body`         required, 1..5000 characters, plain text.
     *   - `website_url`  honeypot — MUST be empty from a real visitor.
     *   - `startedAt`    the token from {@see self::formToken()}.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException The post does not accept comments (keyed
     *         `postId`), an unknown `parentId`, a malformed field, a reused
     *         `startedAt` that is too fresh or stale, or a duplicate
     *         submission — every applicable error at once, keyed exactly as
     *         the input.
     * @throws ServiceException The per-IP submission rate limit was exceeded
     *         (`comment.submit.rate_limited`).
     */
    public function submit(
        string $postId,
        array $input,
        ?Identity $identity,
        string $ip,
        ?string $userAgent,
    ): CommentView;

    /**
     * A page of a post's thread: approved top-level comments, oldest first,
     * each with every approved reply nested underneath — replies are never
     * paginated separately.
     */
    public function thread(string $postId, int $page = 1, int $perPage = 20): CommentPage;

    /** Approved comment count for one post. */
    public function count(string $postId): int;

    /**
     * Approved comment counts for several posts in ONE query — a listing
     * page showing "N comments" under each card.
     *
     * @param list<string> $postIds
     * @return array<string, int> postId => count. A postId with no approved
     *         comments is present with 0, not omitted.
     */
    public function counts(array $postIds): array;

    /**
     * Moderation queue, newest first.
     *
     * @param string|null $status null = every status.
     * @throws ValidationException An unknown `status` (keyed `status`).
     */
    public function list(
        ?string $status = null,
        ?string $postId = null,
        ?string $search = null,
        int $page = 1,
        int $perPage = 20,
    ): CommentAdminPage;

    /** Admin read — any status. Null when no comment has this public id. */
    public function find(string $id): ?CommentAdminView;

    /**
     * Change a comment's status. Approving an already-approved comment is a
     * no-op (no new event).
     *
     * @throws ValidationException An unknown `status` (keyed `status`).
     * @return CommentAdminView|null Null when no comment has this public id.
     */
    public function moderate(string $id, string $status, string $actorId): ?CommentAdminView;

    /**
     * Edit a comment's body (moderation correction — e.g. redacting a leaked
     * email). Does not change status.
     *
     * @throws ValidationException An empty or over-length body (keyed `body`).
     * @return CommentAdminView|null Null when no comment has this public id.
     */
    public function update(string $id, string $body, string $actorId): ?CommentAdminView;

    /** Deletes the comment and every reply beneath it. False when no comment had this id. */
    public function delete(string $id): bool;

    /**
     * Change the status of several comments at once.
     *
     * Rows already in that status are left untouched (an approved comment
     * keeps its first approved_at), and only rows that move to `approved`
     * announce comment.approved.
     *
     * @param list<string> $ids At most 200.
     * @throws ValidationException An unknown `status` (keyed `status`), or
     *                             more than 200 ids (keyed `ids`).
     * @return int How many rows actually changed.
     */
    public function bulkModerate(array $ids, string $status, string $actorId): int;
}
