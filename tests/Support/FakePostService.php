<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Comments\Support;

use Plugins\Posts\API\Contracts\PostServiceContract;
use Plugins\Posts\API\DTO\PostDetail;
use Plugins\Posts\API\DTO\PostPage;
use Plugins\Posts\API\DTO\PostView;
use Plugins\Posts\API\DTO\RevisionView;

/**
 * A hand-written fake of the Posts plugin's published contract — per the task
 * brief, tests use this rather than the real PostService, so this plugin's
 * test suite does not depend on Posts' internals (only its API/Contracts +
 * API/DTO, which are the only things any plugin may import from a sibling).
 *
 * Only {@see self::find()} is exercised by CommentService; every other
 * method is unused by this plugin and throws if ever called, so a test that
 * accidentally relies on one fails loudly instead of silently returning
 * nonsense.
 *
 * NOTE: `PostServiceContract` was under active development by another
 * engineer while this fake was written (its surface grew considerably
 * between the start and the end of this task — translations, revisions,
 * categories, previews, archives, view counts). This file implements the
 * signatures observed at the time this plugin's test suite was finished; if
 * Posts' contract changes again afterwards, `composer update` will surface a
 * fatal "does not implement abstract method" here, which is the correct,
 * loud failure mode for a fake drifting from the interface it fakes.
 */
final class FakePostService implements PostServiceContract
{
    /** @var array<string, PostView> */
    private array $posts = [];

    public function put(PostView $post): void
    {
        $this->posts[$post->id] = $post;
    }

    /**
     * Build a minimal PostView with just the fields CommentService reads
     * (`status`, `commentsEnabled`) wired to the given values, and every
     * other field defaulted. Keeping the defaulting HERE, in one place,
     * means a change to PostView's (long, evolving) constructor only has to
     * be fixed in one spot in this test suite.
     */
    public static function postView(string $id, string $status = 'published', bool $commentsEnabled = true): PostView
    {
        return new PostView(
            id: $id,
            kind: 'blog',
            locale: 'en',
            slug: 'a-post',
            path: '/blog/a-post',
            translationGroup: null,
            title: 'A post',
            subtitle: null,
            excerpt: null,
            summary: '',
            body: null,
            bodyFormat: 'markdown',
            bodyHtml: null,
            toc: [],
            readingMinutes: 1,
            coverImageUrl: null,
            coverImageAlt: null,
            authorId: null,
            authorName: null,
            tags: [],
            categories: [],
            dateline: null,
            contact: null,
            sourceName: null,
            sourceUrl: null,
            metaTitle: null,
            metaDescription: null,
            canonicalUrl: null,
            noindex: false,
            featured: false,
            commentsEnabled: $commentsEnabled,
            published: $status === 'published',
            publishedAt: null,
            status: $status,
            viewCount: 0,
            createdBy: null,
            createdAt: '2026-01-01 00:00:00',
            updatedAt: '2026-01-01 00:00:00',
            deletedAt: null,
        );
    }

    public function kinds(): array
    {
        throw new \RuntimeException('Not used by these tests.');
    }

    public function list(
        ?string $kind = null,
        ?string $status = null,
        ?string $tag = null,
        ?string $search = null,
        int $page = 1,
        int $perPage = 20,
        ?string $locale = null,
        ?string $category = null,
        ?string $authorId = null,
        string $order = 'created',
    ): PostPage {
        throw new \RuntimeException('Not used by these tests.');
    }

    public function statusCounts(
        ?string $kind = null,
        ?string $tag = null,
        ?string $search = null,
        ?string $locale = null,
        ?string $category = null,
        ?string $authorId = null,
    ): array {
        throw new \RuntimeException('Not used by these tests.');
    }

    public function find(string $publicId): ?PostView
    {
        return $this->posts[$publicId] ?? null;
    }

    public function create(array $input, string $actorId): PostView
    {
        throw new \RuntimeException('Not used by these tests.');
    }

    public function update(string $publicId, array $input, string $actorId, ?string $note = null): ?PostView
    {
        throw new \RuntimeException('Not used by these tests.');
    }

    public function trash(string $publicId, string $actorId): bool
    {
        throw new \RuntimeException('Not used by these tests.');
    }

    public function restore(string $publicId, string $actorId): ?PostView
    {
        throw new \RuntimeException('Not used by these tests.');
    }

    public function purge(string $publicId): bool
    {
        throw new \RuntimeException('Not used by these tests.');
    }

    public function duplicate(string $publicId, string $actorId): ?PostView
    {
        throw new \RuntimeException('Not used by these tests.');
    }

    public function revisions(string $publicId, int $limit = 50): array
    {
        throw new \RuntimeException('Not used by these tests.');
    }

    public function revision(string $publicId, int $number): ?RevisionView
    {
        throw new \RuntimeException('Not used by these tests.');
    }

    public function restoreRevision(string $publicId, int $number, string $actorId): ?PostView
    {
        throw new \RuntimeException('Not used by these tests.');
    }

    public function translations(string $publicId): array
    {
        throw new \RuntimeException('Not used by these tests.');
    }

    public function stats(): array
    {
        throw new \RuntimeException('Not used by these tests.');
    }

    public function suggestSlug(string $title, string $kind = 'blog', string $locale = '', ?string $exceptPublicId = null): string
    {
        throw new \RuntimeException('Not used by these tests.');
    }

    public function previewToken(string $publicId, int $ttlSeconds = 86_400): string
    {
        throw new \RuntimeException('Not used by these tests.');
    }

    public function findForPreview(string $token, \DateTimeImmutable $now): ?PostView
    {
        throw new \RuntimeException('Not used by these tests.');
    }

    public function published(
        \DateTimeImmutable $now,
        ?string $kind = null,
        ?string $tag = null,
        ?string $search = null,
        bool $featuredOnly = false,
        int $page = 1,
        int $perPage = 10,
        ?string $locale = null,
        ?string $category = null,
        ?int $year = null,
        ?int $month = null,
        ?string $author = null,
    ): PostPage {
        throw new \RuntimeException('Not used by these tests.');
    }

    public function findPublished(string $kind, string $slug, \DateTimeImmutable $now, ?string $locale = null): ?PostView
    {
        throw new \RuntimeException('Not used by these tests.');
    }

    public function show(string $kind, string $slug, \DateTimeImmutable $now, ?string $locale = null, int $relatedLimit = 3): ?PostDetail
    {
        throw new \RuntimeException('Not used by these tests.');
    }

    public function redirectFor(string $kind, string $slug, \DateTimeImmutable $now, ?string $locale = null): ?PostView
    {
        throw new \RuntimeException('Not used by these tests.');
    }

    public function popular(\DateTimeImmutable $now, ?string $kind = null, ?string $locale = null, int $limit = 5): array
    {
        throw new \RuntimeException('Not used by these tests.');
    }

    public function tags(\DateTimeImmutable $now, ?string $kind = null, int $limit = 50, ?string $locale = null): array
    {
        throw new \RuntimeException('Not used by these tests.');
    }

    public function archive(\DateTimeImmutable $now, ?string $kind = null, ?string $locale = null): array
    {
        throw new \RuntimeException('Not used by these tests.');
    }

    public function recordView(string $publicId, \DateTimeImmutable $now): bool
    {
        throw new \RuntimeException('Not used by these tests.');
    }

    public function publishDue(\DateTimeImmutable $now, int $limit = 100): int
    {
        throw new \RuntimeException('Not used by these tests.');
    }

    public function emptyTrash(\DateTimeImmutable $before, int $limit = 200): int
    {
        throw new \RuntimeException('Not used by these tests.');
    }

    public function rerender(int $limit = 100, ?string $after = null): array
    {
        throw new \RuntimeException('Not used by these tests.');
    }
}
