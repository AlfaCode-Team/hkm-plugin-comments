<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Comments;

use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\DomainEventCollector;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\SecurityException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use PHPUnit\Framework\TestCase;
use Plugins\Comments\Application\Services\CommentService;
use Plugins\Comments\Application\Spam\FormToken;
use Plugins\Comments\Infrastructure\Persistence\CommentRepository;
use Psr\Container\ContainerInterface;
use Tests\Unit\Plugins\Comments\Support\ArrayCachePort;
use Tests\Unit\Plugins\Comments\Support\FakePostService;
use Tests\Unit\Plugins\Comments\Support\FrozenClock;
use Tests\Unit\Plugins\Comments\Support\RecordingListener;
use Tests\Unit\Plugins\Comments\Support\SqliteDatabasePort;

/**
 * End-to-end coverage of CommentService against a REAL SQLite database,
 * migrated through the plugin's own migration file (see SqliteDatabasePort).
 * Posts is faked (FakePostService) — see the task brief for why: this suite
 * must not depend on the sibling plugin's internals, only its published
 * API/Contracts + API/DTO.
 */
final class CommentServiceTest extends TestCase
{
    private const string SECRET = 'test-secret';

    private SqliteDatabasePort $db;

    private FrozenClock $clock;

    private FakePostService $posts;

    protected function setUp(): void
    {
        $this->db    = new SqliteDatabasePort();
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $this->posts = new FakePostService();
        RecordingListener::reset();
    }

    private function container(): ContainerInterface
    {
        return new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException('no bindings');
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
    }

    private function service(
        ?ArrayCachePort $cache = null,
        string $moderationPolicy = 'first',
        int $minSeconds = 3,
        int $maxLinks = 2,
        int $maxDepth = 3,
        int $ratePerMinute = 5,
        ?EventBus $eventBus = null,
    ): CommentService {
        return new CommentService(
            repository: new CommentRepository($this->db),
            transaction: new TransactionManager($this->db),
            collector: new DomainEventCollector(),
            eventBus: $eventBus ?? new EventBus($this->container()),
            clock: $this->clock,
            posts: $this->posts,
            formToken: new FormToken(self::SECRET),
            cache: $cache,
            moderationPolicy: $moderationPolicy,
            moderatePermission: 'comments:moderate',
            minSeconds: $minSeconds,
            maxLinks: $maxLinks,
            maxDepth: $maxDepth,
            ratePerMinute: $ratePerMinute,
            ipSalt: 'salt',
        );
    }

    private function registerPost(string $postId, string $status = 'published', bool $commentsEnabled = true): void
    {
        $this->posts->put(FakePostService::postView($postId, $status, $commentsEnabled));
    }

    /** A valid, non-stale, non-too-fast token for $service, minted now and the clock advanced past COMMENTS_MIN_SECONDS. */
    private function tokenFor(CommentService $service): string
    {
        $token = $service->formToken();
        $this->clock->advance(5);

        return $token;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function input(array $overrides, CommentService $service): array
    {
        $base = [
            'authorName'  => 'Jane Doe',
            'authorEmail' => 'jane@example.com',
            'body'        => 'Nice article, thanks for sharing!',
            'website_url' => '',
        ];

        // Only mint (and advance the clock for) a token when the test did NOT
        // supply its own — several tests need an exact, un-advanced timestamp
        // to exercise the too-fast / expired checks.
        if (!array_key_exists('startedAt', $overrides)) {
            $base['startedAt'] = $this->tokenFor($service);
        }

        return array_merge($base, $overrides);
    }

    // ── submit(): moderation policy ─────────────────────────────────────────

    public function test_submit_is_pending_under_default_first_policy_with_no_prior_approval(): void
    {
        $this->registerPost('POST01');
        $service = $this->service();

        $view = $service->submit('POST01', $this->input([], $service), null, '127.0.0.1', 'UA');

        self::assertSame('pending', $view->status);
        self::assertSame('POST01', $view->postId);
        self::assertNull($view->parentId);
        self::assertSame(0, $view->depth);
    }

    public function test_submit_auto_approves_under_none_policy(): void
    {
        $this->registerPost('POST01');
        $service = $this->service(moderationPolicy: 'none');

        $view = $service->submit('POST01', $this->input([], $service), null, '127.0.0.1', 'UA');

        self::assertSame('approved', $view->status);
    }

    public function test_submit_is_pending_under_all_policy_even_with_prior_approval(): void
    {
        $this->registerPost('POST01');
        $service = $this->service(moderationPolicy: 'all');

        $view = $service->submit('POST01', $this->input(['body' => 'first comment'], $service), null, '127.0.0.1', 'UA');

        self::assertSame('pending', $view->status);
    }

    public function test_first_policy_approves_once_the_email_has_an_approved_comment(): void
    {
        $this->registerPost('POST01');
        $service = $this->service();

        $first = $service->submit('POST01', $this->input(['body' => 'first comment'], $service), null, '127.0.0.1', 'UA');
        self::assertSame('pending', $first->status);

        $service->moderate($first->id, 'approved', 'admin-1');

        $second = $service->submit('POST01', $this->input(['body' => 'second comment, different text'], $service), null, '127.0.0.1', 'UA');
        self::assertSame('approved', $second->status);
    }

    public function test_a_moderator_identity_is_auto_approved_and_defaults_name_and_email(): void
    {
        $this->registerPost('POST01');
        $service  = $this->service();
        $identity = Identity::asUser('mod-1', permissions: ['comments:moderate'], email: 'mod@example.com', fullName: 'Mod');

        $view = $service->submit(
            'POST01',
            $this->input(['authorEmail' => null, 'authorName' => null], $service),
            $identity,
            '127.0.0.1',
            'UA',
        );

        self::assertSame('approved', $view->status);
        self::assertSame('Mod', $view->authorName);
    }

    // ── spam guard ───────────────────────────────────────────────────────────

    public function test_honeypot_filled_results_in_spam_not_an_error(): void
    {
        $this->registerPost('POST01');
        $service = $this->service();

        $view = $service->submit('POST01', $this->input(['website_url' => 'http://bot.example'], $service), null, '127.0.0.1', 'UA');

        self::assertSame('spam', $view->status);
    }

    public function test_too_many_links_results_in_spam_not_an_error(): void
    {
        $this->registerPost('POST01');
        $service = $this->service(maxLinks: 1);

        $view = $service->submit(
            'POST01',
            $this->input(['body' => 'http://a.com http://b.com http://c.com'], $service),
            null,
            '127.0.0.1',
            'UA',
        );

        self::assertSame('spam', $view->status);
    }

    public function test_too_fast_submission_is_rejected(): void
    {
        $this->registerPost('POST01');
        $service = $this->service();
        $token   = $service->formToken(); // minted now; clock NOT advanced

        try {
            $service->submit('POST01', $this->input(['startedAt' => $token], $service), null, '127.0.0.1', 'UA');
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('startedAt', $e->errors);
        }
    }

    public function test_expired_token_is_rejected(): void
    {
        $this->registerPost('POST01');
        $service = $this->service();
        $token   = $service->formToken();
        $this->clock->advance(86_401);

        try {
            $service->submit('POST01', $this->input(['startedAt' => $token], $service), null, '127.0.0.1', 'UA');
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('startedAt', $e->errors);
        }
    }

    public function test_a_malformed_token_is_rejected(): void
    {
        $this->registerPost('POST01');
        $service = $this->service();

        $this->expectException(ValidationException::class);
        $service->submit('POST01', $this->input(['startedAt' => 'not-a-token'], $service), null, '127.0.0.1', 'UA');
    }

    public function test_duplicate_submission_within_the_window_is_rejected(): void
    {
        $this->registerPost('POST01');
        $service = $this->service();

        $service->submit('POST01', $this->input(['body' => 'Exactly the same text.'], $service), null, '127.0.0.1', 'UA');

        $this->clock->advance(60);

        try {
            $service->submit('POST01', $this->input(['body' => 'Exactly the same text.'], $service), null, '127.0.0.1', 'UA');
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('body', $e->errors);
        }
    }

    public function test_the_same_body_after_the_duplicate_window_is_allowed(): void
    {
        $this->registerPost('POST01');
        $service = $this->service();

        $service->submit('POST01', $this->input(['body' => 'Exactly the same text.'], $service), null, '127.0.0.1', 'UA');

        $this->clock->advance(601);

        $second = $service->submit('POST01', $this->input(['body' => 'Exactly the same text.'], $service), null, '127.0.0.1', 'UA');
        self::assertSame('pending', $second->status);
    }

    public function test_an_unknown_parent_is_rejected(): void
    {
        $this->registerPost('POST01');
        $service = $this->service();

        $this->expectException(ValidationException::class);
        $service->submit('POST01', $this->input(['parentId' => 'NOTAREALCOMMENTIDXXXXXXXXX'], $service), null, '127.0.0.1', 'UA');
    }

    public function test_rate_limit_rejects_once_the_per_ip_threshold_is_exceeded(): void
    {
        $this->registerPost('POST01');
        $cache   = new ArrayCachePort();
        $service = $this->service(cache: $cache, ratePerMinute: 1);

        $service->submit('POST01', $this->input(['body' => 'one'], $service), null, '203.0.113.1', 'UA');

        $this->expectException(SecurityException::class);
        $this->expectExceptionCode(429);
        $service->submit('POST01', $this->input(['body' => 'two'], $service), null, '203.0.113.1', 'UA');
    }

    public function test_rate_limit_is_scoped_per_ip(): void
    {
        $this->registerPost('POST01');
        $cache   = new ArrayCachePort();
        $service = $this->service(cache: $cache, ratePerMinute: 1);

        $service->submit('POST01', $this->input(['body' => 'one'], $service), null, '203.0.113.1', 'UA');
        $view = $service->submit('POST01', $this->input(['body' => 'two'], $service), null, '203.0.113.2', 'UA');

        self::assertSame('pending', $view->status);
    }

    public function test_without_a_cache_the_rate_limit_is_not_enforced(): void
    {
        $this->registerPost('POST01');
        $service = $this->service(cache: null, ratePerMinute: 1);

        $service->submit('POST01', $this->input(['body' => 'one'], $service), null, '203.0.113.1', 'UA');
        $view = $service->submit('POST01', $this->input(['body' => 'two'], $service), null, '203.0.113.1', 'UA');

        self::assertSame('pending', $view->status);
    }

    public function test_post_not_accepting_comments_is_rejected(): void
    {
        $this->registerPost('POST01', status: 'draft');
        $service = $this->service();

        $this->expectException(ValidationException::class);
        $service->submit('POST01', $this->input([], $service), null, '127.0.0.1', 'UA');
    }

    public function test_post_with_comments_disabled_is_rejected(): void
    {
        $this->registerPost('POST01', commentsEnabled: false);
        $service = $this->service();

        $this->expectException(ValidationException::class);
        $service->submit('POST01', $this->input([], $service), null, '127.0.0.1', 'UA');
    }

    public function test_an_unknown_post_is_rejected(): void
    {
        $service = $this->service();

        $this->expectException(ValidationException::class);
        $service->submit('MISSING-POST', $this->input([], $service), null, '127.0.0.1', 'UA');
    }

    // ── threading / depth clamp ──────────────────────────────────────────────

    public function test_depth_is_clamped_and_a_too_deep_reply_attaches_to_the_grandparent(): void
    {
        $this->registerPost('POST01');
        $service = $this->service(moderationPolicy: 'none', maxDepth: 1);

        $root = $service->submit('POST01', $this->input(['body' => 'root'], $service), null, '127.0.0.1', 'UA');

        $reply = $service->submit('POST01', $this->input(['body' => 'reply', 'parentId' => $root->id], $service), null, '127.0.0.1', 'UA');
        self::assertSame(1, $reply->depth);
        self::assertSame($root->id, $reply->parentId);

        // $reply is now AT maxDepth (1): a reply to it must not nest further —
        // it attaches to $reply's own parent ($root) instead, staying at depth 1.
        $replyToReply = $service->submit(
            'POST01',
            $this->input(['body' => 'reply to reply', 'parentId' => $reply->id], $service),
            null,
            '127.0.0.1',
            'UA',
        );

        self::assertSame(1, $replyToReply->depth);
        self::assertSame($root->id, $replyToReply->parentId);
    }

    public function test_thread_nests_approved_replies_under_their_parent_oldest_first(): void
    {
        $this->registerPost('POST01');
        $service = $this->service(moderationPolicy: 'none');

        $root = $service->submit('POST01', $this->input(['body' => 'root comment'], $service), null, '127.0.0.1', 'UA');
        $this->clock->advance(10);
        $service->submit('POST01', $this->input(['body' => 'reply A', 'parentId' => $root->id], $service), null, '127.0.0.1', 'UA');
        $this->clock->advance(10);
        $service->submit('POST01', $this->input(['body' => 'reply B', 'parentId' => $root->id], $service), null, '127.0.0.1', 'UA');

        $page = $service->thread('POST01');

        self::assertSame(1, $page->total);
        self::assertCount(1, $page->items);
        self::assertCount(2, $page->items[0]->replies);
        self::assertSame('reply A', $page->items[0]->replies[0]->body);
        self::assertSame('reply B', $page->items[0]->replies[1]->body);
    }

    public function test_thread_never_includes_pending_or_spam_comments(): void
    {
        $this->registerPost('POST01');
        $service = $this->service(); // default 'first' => pending

        $service->submit('POST01', $this->input(['body' => 'pending one'], $service), null, '127.0.0.1', 'UA');

        $page = $service->thread('POST01');

        self::assertSame(0, $page->total);
        self::assertCount(0, $page->items);
    }

    public function test_count_and_counts(): void
    {
        $this->registerPost('POST01');
        $this->registerPost('POST02');
        $service = $this->service(moderationPolicy: 'none');

        $service->submit('POST01', $this->input(['body' => 'a'], $service), null, '127.0.0.1', 'UA');
        $service->submit('POST01', $this->input(['body' => 'b'], $service), null, '127.0.0.1', 'UA');
        $service->submit('POST02', $this->input(['body' => 'c'], $service), null, '127.0.0.1', 'UA');

        self::assertSame(2, $service->count('POST01'));
        self::assertSame(
            ['POST01' => 2, 'POST02' => 1, 'POST03' => 0],
            $service->counts(['POST01', 'POST02', 'POST03']),
        );
    }

    // ── admin surface ────────────────────────────────────────────────────────

    public function test_list_find_moderate_update_delete(): void
    {
        $this->registerPost('POST01');
        $service = $this->service();

        $view = $service->submit('POST01', $this->input(['body' => 'needs moderation'], $service), null, '127.0.0.1', 'UA');
        self::assertSame('pending', $view->status);

        $page = $service->list(status: 'pending');
        self::assertSame(1, $page->total);
        self::assertSame($view->id, $page->items[0]->id);

        $found = $service->find($view->id);
        self::assertNotNull($found);
        self::assertSame('jane@example.com', $found->authorEmail);

        $approved = $service->moderate($view->id, 'approved', 'admin-1');
        self::assertSame('approved', $approved->status);

        $edited = $service->update($view->id, 'Edited body.', 'admin-1');
        self::assertSame('Edited body.', $edited->body);

        self::assertTrue($service->delete($view->id));
        self::assertNull($service->find($view->id));
        self::assertFalse($service->delete($view->id));
    }

    public function test_moderate_update_and_delete_return_null_or_false_for_an_unknown_id(): void
    {
        $service = $this->service();

        self::assertNull($service->moderate('01J00000000000000000000001', 'approved', 'admin-1'));
        self::assertNull($service->update('01J00000000000000000000001', 'new body', 'admin-1'));
        self::assertFalse($service->delete('01J00000000000000000000001'));
    }

    public function test_delete_cascades_to_replies(): void
    {
        $this->registerPost('POST01');
        $service = $this->service(moderationPolicy: 'none');

        $root  = $service->submit('POST01', $this->input(['body' => 'root'], $service), null, '127.0.0.1', 'UA');
        $reply = $service->submit('POST01', $this->input(['body' => 'reply', 'parentId' => $root->id], $service), null, '127.0.0.1', 'UA');

        self::assertTrue($service->delete($root->id));
        self::assertNull($service->find($root->id));
        self::assertNull($service->find($reply->id));
    }

    public function test_bulk_moderate_changes_every_matching_row(): void
    {
        $this->registerPost('POST01');
        $service = $this->service();

        $a = $service->submit('POST01', $this->input(['body' => 'aaa'], $service), null, '127.0.0.1', 'UA');
        $b = $service->submit('POST01', $this->input(['body' => 'bbb'], $service), null, '127.0.0.1', 'UA');

        $changed = $service->bulkModerate([$a->id, $b->id], 'approved', 'admin-1');

        self::assertSame(2, $changed);
        self::assertSame('approved', $service->find($a->id)?->status);
        self::assertSame('approved', $service->find($b->id)?->status);
    }

    public function test_bulk_re_approval_changes_nothing_and_announces_nothing(): void
    {
        $this->registerPost('POST01');
        $bus = new EventBus($this->container());
        $bus->subscribe('comment.approved', RecordingListener::class);
        $service = $this->service(eventBus: $bus);

        $a = $service->submit('POST01', $this->input(['body' => 'aaa'], $service), null, '127.0.0.1', 'UA');
        $b = $service->submit('POST01', $this->input(['body' => 'bbb'], $service), null, '127.0.0.1', 'UA');

        self::assertSame(1, $service->bulkModerate([$a->id], 'approved', 'admin-1'));
        $firstApproval = $service->find($a->id)?->approvedAt;
        self::assertCount(1, RecordingListener::$received['comment.approved'] ?? []);

        $this->clock->advance(3600);
        // $a is already approved: only $b changes, only $b is announced, and
        // $a keeps the time it was first approved. A nested array is ignored.
        self::assertSame(1, $service->bulkModerate([$a->id, strtolower($b->id), [$a->id]], 'approved', 'admin-1'));
        self::assertCount(2, RecordingListener::$received['comment.approved'] ?? []);
        self::assertSame($b->id, RecordingListener::$received['comment.approved'][1]->commentId);
        self::assertSame($firstApproval, $service->find($a->id)?->approvedAt);
    }

    public function test_bulk_moderate_refuses_more_than_the_cap(): void
    {
        $ids = array_map(static fn(int $i): string => sprintf('%026d', $i), range(1, CommentService::MAX_BULK + 1));

        try {
            $this->service()->bulkModerate($ids, 'approved', 'admin-1');
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('ids', $e->errors);
        }
    }

    public function test_an_unknown_status_is_rejected_by_list(): void
    {
        $service = $this->service();

        $this->expectException(ValidationException::class);
        $service->list(status: 'not-a-status');
    }

    public function test_an_unknown_status_is_rejected_by_moderate(): void
    {
        $this->registerPost('POST01');
        $service = $this->service();
        $view    = $service->submit('POST01', $this->input([], $service), null, '127.0.0.1', 'UA');

        $this->expectException(ValidationException::class);
        $service->moderate($view->id, 'not-a-status', 'admin-1');
    }

    // ── events ───────────────────────────────────────────────────────────────

    public function test_submit_dispatches_submitted_and_approved_events_after_commit(): void
    {
        $this->registerPost('POST01');
        $bus = new EventBus($this->container());
        $bus->subscribe('comment.submitted', RecordingListener::class);
        $bus->subscribe('comment.approved', RecordingListener::class);
        $service = $this->service(moderationPolicy: 'none', eventBus: $bus);

        $view = $service->submit('POST01', $this->input([], $service), null, '127.0.0.1', 'UA');

        self::assertCount(1, RecordingListener::$received['comment.submitted'] ?? []);
        self::assertCount(1, RecordingListener::$received['comment.approved'] ?? []);
        self::assertSame($view->id, RecordingListener::$received['comment.submitted'][0]->commentId);
    }

    public function test_a_pending_submission_does_not_dispatch_approved(): void
    {
        $this->registerPost('POST01');
        $bus = new EventBus($this->container());
        $bus->subscribe('comment.submitted', RecordingListener::class);
        $bus->subscribe('comment.approved', RecordingListener::class);
        $service = $this->service(moderationPolicy: 'all', eventBus: $bus);

        $service->submit('POST01', $this->input([], $service), null, '127.0.0.1', 'UA');

        self::assertCount(1, RecordingListener::$received['comment.submitted'] ?? []);
        self::assertArrayNotHasKey('comment.approved', RecordingListener::$received);
    }

    public function test_moderate_dispatches_approved_only_on_the_transition(): void
    {
        $this->registerPost('POST01');
        $bus = new EventBus($this->container());
        $bus->subscribe('comment.approved', RecordingListener::class);
        $service = $this->service(eventBus: $bus);

        $view = $service->submit('POST01', $this->input([], $service), null, '127.0.0.1', 'UA');
        self::assertSame('pending', $view->status);

        $service->moderate($view->id, 'approved', 'admin-1');
        self::assertCount(1, RecordingListener::$received['comment.approved'] ?? []);

        // Re-approving is a no-op in the domain — no second event.
        $service->moderate($view->id, 'approved', 'admin-1');
        self::assertCount(1, RecordingListener::$received['comment.approved'] ?? []);
    }
}
