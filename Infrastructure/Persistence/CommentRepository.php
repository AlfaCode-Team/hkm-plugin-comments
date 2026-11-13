<?php

declare(strict_types=1);

namespace Plugins\Comments\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Comments\Domain\Entities\Comment;
use Plugins\Comments\Domain\ValueObjects\CommentStatus;

/**
 * CommentRepository — DatabasePort ONLY. Owns `comments`.
 *
 * The injected DatabasePort is the REQUEST's connection: the tenant database
 * on a Tenancy host, the central one otherwise — same convention as the
 * sibling Posts plugin. There is no `tenant_id` column; the database is the
 * tenant boundary.
 *
 * Portability rules this class keeps (mirrors PostRepository):
 *   - LIMIT/OFFSET are inlined validated ints, never bound.
 *   - No named placeholder is used twice in one statement.
 *   - Only ANSI functions: LOWER, COALESCE, COUNT. Dates are stored as
 *     `Y-m-d H:i:s` UTC strings.
 *   - Search uses `LIKE … ESCAPE '!'`.
 */
final class CommentRepository
{
    private const string TABLE = 'comments';

    private const string COLUMNS = 'public_id, post_id, parent_id, depth, author_user_id, author_name,
        author_email, author_url, body, status, ip_hash, user_agent, created_at, updated_at, approved_at';

    /** Hard ceiling on one page, whatever a caller asks for. */
    public const int MAX_PER_PAGE = 100;

    public function __construct(
        private readonly DatabasePort $db,
    ) {
    }

    public function insert(Comment $comment): void
    {
        $this->write(
            'INSERT INTO ' . self::TABLE . '
                (public_id, post_id, parent_id, depth, author_user_id, author_name, author_email, author_url,
                 body, status, ip_hash, user_agent, created_at, updated_at, approved_at)
             VALUES
                (:public_id, :post_id, :parent_id, :depth, :author_user_id, :author_name, :author_email, :author_url,
                 :body, :status, :ip_hash, :user_agent, :created_at, :updated_at, :approved_at)',
            $this->toRow($comment),
            'Failed to insert comment.',
            $comment->id()->value(),
        );
    }

    /** Full replace of the mutable columns (body/status/approvedAt). False when the row is gone. */
    public function update(Comment $comment): bool
    {
        return $this->write(
            'UPDATE ' . self::TABLE . ' SET
                body = :body, status = :status, approved_at = :approved_at, updated_at = :updated_at
             WHERE public_id = :public_id',
            [
                'body'         => $comment->body()->value(),
                'status'       => $comment->status()->value,
                'approved_at'  => $comment->approvedAt() === null ? null : self::formatInstant($comment->approvedAt()),
                'updated_at'   => self::formatInstant($comment->updatedAt()),
                'public_id'    => $comment->id()->value(),
            ],
            'Failed to update comment.',
            $comment->id()->value(),
        ) > 0;
    }

    /**
     * Change the status of several comments at once. Returns how many rows
     * actually matched.
     *
     * @param list<string> $publicIds
     */
    public function bulkUpdateStatus(array $publicIds, CommentStatus $status, ?\DateTimeImmutable $approvedAt, \DateTimeImmutable $now): int
    {
        if ($publicIds === []) {
            return 0;
        }

        $placeholders = [];
        $params       = [
            'status'      => $status->value,
            'approved_at' => $approvedAt === null ? null : self::formatInstant($approvedAt),
            'updated_at'  => self::formatInstant($now),
        ];

        foreach (array_values($publicIds) as $i => $id) {
            $placeholders[]       = ":id{$i}";
            $params["id{$i}"] = $id;
        }

        // `status <> :unchanged` leaves rows already in the target status alone:
        // re-approving keeps the original approved_at, and the count is the
        // rows that really changed.
        $params['unchanged'] = $status->value;

        return $this->write(
            'UPDATE ' . self::TABLE . ' SET status = :status, approved_at = :approved_at, updated_at = :updated_at
             WHERE public_id IN (' . implode(', ', $placeholders) . ') AND status <> :unchanged',
            $params,
            'Failed to bulk-update comment status.',
            implode(',', $publicIds),
        );
    }

    /**
     * Deletes the comment and every reply beneath it (any depth). False when
     * no comment had this id.
     */
    public function delete(string $publicId): bool
    {
        $ids = $this->collectSubtreeIds($publicId);

        if ($ids === []) {
            return false;
        }

        $placeholders = [];
        $params       = [];
        foreach ($ids as $i => $id) {
            $placeholders[]  = ":id{$i}";
            $params["id{$i}"] = $id;
        }

        $this->write(
            'DELETE FROM ' . self::TABLE . ' WHERE public_id IN (' . implode(', ', $placeholders) . ')',
            $params,
            'Failed to delete comment.',
            $publicId,
        );

        return true;
    }

    public function findByPublicId(string $publicId): ?Comment
    {
        $row = $this->readOne(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . ' WHERE public_id = :public_id',
            ['public_id' => $publicId],
            'Failed to load comment.',
        );

        return $row === null ? null : Comment::reconstitute($row);
    }

    /**
     * Several comments in one query, keyed by public id. Ids with no row are
     * absent from the result.
     *
     * @param list<string> $publicIds
     * @return array<string, Comment>
     */
    public function findManyByPublicIds(array $publicIds): array
    {
        if ($publicIds === []) {
            return [];
        }

        $placeholders = [];
        $params       = [];
        foreach (array_values($publicIds) as $i => $id) {
            $placeholders[]   = ":id{$i}";
            $params["id{$i}"] = $id;
        }

        $byId = [];
        foreach ($this->query(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . ' WHERE public_id IN (' . implode(', ', $placeholders) . ')',
            $params,
            'Failed to load comments.',
        ) as $row) {
            $comment                         = Comment::reconstitute($row);
            $byId[$comment->id()->value()] = $comment;
        }

        return $byId;
    }

    /** True when this email already has at least one approved comment (any post). */
    public function hasApprovedByEmail(string $email): bool
    {
        return $this->readOne(
            'SELECT public_id FROM ' . self::TABLE . ' WHERE author_email = :email AND status = :status',
            ['email' => $email, 'status' => CommentStatus::Approved->value],
            'Failed to check prior approved comments.',
        ) !== null;
    }

    /** True when the same post + email + body were submitted within the window ending at $now. */
    public function hasRecentDuplicate(string $postId, string $email, string $body, \DateTimeImmutable $since): bool
    {
        return $this->readOne(
            'SELECT public_id FROM ' . self::TABLE . '
             WHERE post_id = :post_id AND author_email = :email AND body = :body AND created_at >= :since',
            ['post_id' => $postId, 'email' => $email, 'body' => $body, 'since' => self::formatInstant($since)],
            'Failed to check for a duplicate comment.',
        ) !== null;
    }

    /** @return list<Comment> Approved top-level comments for a post, oldest first. */
    public function threadTopLevel(string $postId, int $limit, int $offset): array
    {
        $limit  = max(1, min($limit, self::MAX_PER_PAGE));
        $offset = max(0, $offset);

        $rows = $this->query(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . '
             WHERE post_id = :post_id AND status = :status AND parent_id IS NULL
             ORDER BY created_at ASC, id ASC LIMIT ' . $limit . ' OFFSET ' . $offset,
            ['post_id' => $postId, 'status' => CommentStatus::Approved->value],
            'Failed to load comment thread.',
        );

        return array_map(Comment::reconstitute(...), $rows);
    }

    /** @return list<Comment> Every approved reply for a post, oldest first — nested in PHP by the service. */
    public function threadReplies(string $postId): array
    {
        $rows = $this->query(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . '
             WHERE post_id = :post_id AND status = :status AND parent_id IS NOT NULL
             ORDER BY created_at ASC, id ASC',
            ['post_id' => $postId, 'status' => CommentStatus::Approved->value],
            'Failed to load comment replies.',
        );

        return array_map(Comment::reconstitute(...), $rows);
    }

    public function countApprovedTopLevel(string $postId): int
    {
        return $this->scalarCount(
            'SELECT COUNT(*) AS aggregate FROM ' . self::TABLE . '
             WHERE post_id = :post_id AND status = :status AND parent_id IS NULL',
            ['post_id' => $postId, 'status' => CommentStatus::Approved->value],
        );
    }

    public function countApproved(string $postId): int
    {
        return $this->scalarCount(
            'SELECT COUNT(*) AS aggregate FROM ' . self::TABLE . ' WHERE post_id = :post_id AND status = :status',
            ['post_id' => $postId, 'status' => CommentStatus::Approved->value],
        );
    }

    /**
     * Approved counts for several posts in ONE query.
     *
     * @param list<string> $postIds
     * @return array<string, int> postId => count, 0 for a postId with none.
     */
    public function countsApproved(array $postIds): array
    {
        $counts = array_fill_keys($postIds, 0);

        if ($postIds === []) {
            return $counts;
        }

        $placeholders = [];
        $params       = ['status' => CommentStatus::Approved->value];
        foreach (array_values($postIds) as $i => $id) {
            $placeholders[]       = ":p{$i}";
            $params["p{$i}"] = $id;
        }

        $rows = $this->query(
            'SELECT post_id, COUNT(*) AS aggregate FROM ' . self::TABLE . '
             WHERE status = :status AND post_id IN (' . implode(', ', $placeholders) . ')
             GROUP BY post_id',
            $params,
            'Failed to count comments.',
        );

        foreach ($rows as $row) {
            $counts[(string) $row['post_id']] = (int) $row['aggregate'];
        }

        return $counts;
    }

    /** @return list<Comment> */
    public function search(CommentCriteria $criteria, int $limit, int $offset): array
    {
        $limit  = max(1, min($limit, self::MAX_PER_PAGE));
        $offset = max(0, $offset);

        [$where, $params] = $this->where($criteria);

        $rows = $this->query(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . $where
                . ' ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params,
            'Failed to list comments.',
        );

        return array_map(Comment::reconstitute(...), $rows);
    }

    public function count(CommentCriteria $criteria): int
    {
        [$where, $params] = $this->where($criteria);

        return $this->scalarCount('SELECT COUNT(*) AS aggregate FROM ' . self::TABLE . $where, $params);
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function where(CommentCriteria $criteria): array
    {
        $clauses = [];
        $params  = [];

        if ($criteria->status !== null) {
            $clauses[]        = 'status = :status';
            $params['status'] = $criteria->status->value;
        }

        if ($criteria->postId !== null) {
            $clauses[]         = 'post_id = :post_id';
            $params['post_id'] = $criteria->postId;
        }

        if ($criteria->search !== null && $criteria->search !== '') {
            $pattern      = '%' . strtr(mb_strtolower($criteria->search), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $clauses[]    = "(LOWER(body) LIKE :q1 ESCAPE '!' OR LOWER(author_name) LIKE :q2 ESCAPE '!' OR LOWER(author_email) LIKE :q3 ESCAPE '!')";
            $params['q1'] = $pattern;
            $params['q2'] = $pattern;
            $params['q3'] = $pattern;
        }

        return [$clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses), $params];
    }

    /**
     * Breadth-first collection of a comment's id and every descendant's id,
     * one query per level (bounded by thread depth, never by comment count).
     * No recursive CTE: `WITH RECURSIVE` is not portable to every driver this
     * plugin's migration targets.
     *
     * @return list<string>
     */
    private function collectSubtreeIds(string $rootId): array
    {
        $root = $this->readOne(
            'SELECT public_id FROM ' . self::TABLE . ' WHERE public_id = :public_id',
            ['public_id' => $rootId],
            'Failed to load comment.',
        );

        if ($root === null) {
            return [];
        }

        $ids      = [$rootId];
        $frontier = [$rootId];

        while ($frontier !== []) {
            $placeholders = [];
            $params       = [];
            foreach (array_values($frontier) as $i => $id) {
                $placeholders[]       = ":pid{$i}";
                $params["pid{$i}"] = $id;
            }

            $children = $this->query(
                'SELECT public_id FROM ' . self::TABLE . ' WHERE parent_id IN (' . implode(', ', $placeholders) . ')',
                $params,
                'Failed to load comment replies for deletion.',
            );

            $frontier = [];
            foreach ($children as $child) {
                $id         = (string) $child['public_id'];
                $ids[]      = $id;
                $frontier[] = $id;
            }
        }

        return $ids;
    }

    private function scalarCount(string $sql, array $params): int
    {
        $row = $this->readOne($sql, $params, 'Failed to count comments.');

        return $row === null ? 0 : (int) array_values($row)[0];
    }

    /** @return array<string, mixed> */
    private function toRow(Comment $comment): array
    {
        return [
            'public_id'       => $comment->id()->value(),
            'post_id'         => $comment->postId(),
            'parent_id'       => $comment->parentId(),
            'depth'           => $comment->depth(),
            'author_user_id'  => $comment->authorUserId(),
            'author_name'     => $comment->authorName(),
            'author_email'    => $comment->authorEmail()->value(),
            'author_url'      => $comment->authorUrl()?->value(),
            'body'            => $comment->body()->value(),
            'status'          => $comment->status()->value,
            'ip_hash'         => $comment->ipHash(),
            'user_agent'      => $comment->userAgent(),
            'created_at'      => self::formatInstant($comment->createdAt()),
            'updated_at'      => self::formatInstant($comment->updatedAt()),
            'approved_at'     => $comment->approvedAt() === null ? null : self::formatInstant($comment->approvedAt()),
        ];
    }

    /** @param array<string, mixed> $params */
    private function write(string $sql, array $params, string $message, string $publicId): int
    {
        try {
            return $this->db->execute($sql, $params);
        } catch (\Throwable $e) {
            throw new RepositoryException(
                $message,
                layer: 'repository.comments',
                context: ['publicId' => $publicId],
                previous: $e,
            );
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    private function readOne(string $sql, array $params, string $message): ?array
    {
        try {
            return $this->db->queryOne($sql, $params);
        } catch (\Throwable $e) {
            throw new RepositoryException($message, layer: 'repository.comments', previous: $e);
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function query(string $sql, array $params, string $message): array
    {
        try {
            return $this->db->query($sql, $params);
        } catch (\Throwable $e) {
            throw new RepositoryException($message, layer: 'repository.comments', previous: $e);
        }
    }

    private static function formatInstant(\DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
