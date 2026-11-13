<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Comments\Feature;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Kernel;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;
use PHPUnit\Framework\TestCase;
use Plugins\Comments\Provider as CommentsProvider;
use Plugins\Posts\Provider as PostsProvider;
use Tests\Unit\Plugins\Comments\Support\Kernel\ArrayCache;
use Tests\Unit\Plugins\Comments\Support\Kernel\DatabaseManagementStub;
use Tests\Unit\Plugins\Comments\Support\Kernel\Pageflow\PageflowStub;
use Tests\Unit\Plugins\Comments\Support\Kernel\PassThroughLayer;
use Tests\Unit\Plugins\Comments\Support\Kernel\TestResponse;
use Tests\Unit\Plugins\Comments\Support\SqliteDatabasePort;

/**
 * Comments AND Posts booted together in a REAL kernel, on one database built
 * by both plugins' migrations — the shape a project runs. Routes compiled by
 * the real boot pipeline, filters registered by the real Providers, controllers
 * resolved under real scope isolation.
 */
final class CommentsHttpTest extends TestCase
{
    private string $root;
    private SqliteDatabasePort $db;
    private Kernel $kernel;

    /** @var array<string, ?string> */
    private array $previousEnv = [];
    private ?string $previousBase = null;
    private ?string $previousProject = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/comments-feature-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/var/cache/manifests', 0775, true);

        $this->previousBase    = Paths::base();
        $this->previousProject = Paths::project();

        $this->setEnv('BOOT_CACHE', '0');
        $this->setEnv('APP_KEY', 'feature-test-key-0123456789abcdef');
        $this->setEnv('COMMENTS_MIN_SECONDS', '0');

        $posts    = dirname((new \ReflectionClass(PostsProvider::class))->getFileName());
        $this->db = new SqliteDatabasePort(glob($posts . '/database/migrations/*.php') ?: []);

        Paths::setBase($this->root);
        Paths::setProject($this->root);

        $this->kernel = Kernel::configure()
            ->withBasePath($this->root)
            ->withProjectPath($this->root)
            ->withPorts([DatabasePort::class => $this->db, CachePort::class => new ArrayCache()])
            ->withSecurity([new PassThroughLayer()])
            ->withModules([DatabaseManagementStub::class, PageflowStub::class, PostsProvider::class, CommentsProvider::class])
            ->build();
    }

    protected function tearDown(): void
    {
        Paths::setBase((string) $this->previousBase);
        Paths::setProject($this->previousProject);

        foreach ($this->previousEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                $_ENV[$key] = $_SERVER[$key] = $value;
            }
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->root);
    }

    private function setEnv(string $key, string $value): void
    {
        if (!array_key_exists($key, $this->previousEnv)) {
            $this->previousEnv[$key] = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        }
        $_ENV[$key] = $_SERVER[$key] = $value;
    }

    /** @param array<string, mixed>|null $json */
    private function call(string $method, string $uri, ?array $json = null, ?Identity $as = null): TestResponse
    {
        $request = Request::create(
            'https://example.test' . $uri,
            $method,
            server: ['REMOTE_ADDR' => '203.0.113.7'] + ($json === null ? [] : ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json']),
            content: $json === null ? null : json_encode($json, JSON_THROW_ON_ERROR),
        );

        if ($as !== null) {
            $request = $request->withIdentity($as);
        }

        return new TestResponse($this->kernel->http()->handle($request));
    }

    private function post(array $overrides = []): string
    {
        $editor = new Identity('erin', 't', [], ['posts:manage'], 'session');

        return $this->call('POST', '/api/admin/posts', $overrides + ['title' => 'Hello', 'published' => true], $editor)
            ->assertCreated()->json()['data']['id'];
    }

    /** @return array<string, mixed> */
    private function comment(string $body = 'Great read!', string $email = 'reader@example.test'): array
    {
        $token = $this->call('GET', '/api/comments/form-token')->assertOk()->json()['data']['token'];

        return ['authorName' => 'Reader', 'authorEmail' => $email, 'body' => $body, 'startedAt' => $token];
    }

    public function test_a_reader_comments_on_a_published_post_and_a_moderator_approves_it(): void
    {
        $postId = $this->post();

        $submitted = $this->call('POST', "/api/comments/posts/{$postId}", $this->comment())->assertCreated()->json();
        $this->assertSame('pending', $submitted['status'] ?? $submitted['data']['status'] ?? null, 'a first-time commenter is held');
        $commentId = $submitted['id'] ?? $submitted['data']['id'];
        $this->assertArrayNotHasKey('authorEmail', $submitted['data'] ?? $submitted, 'the public view never carries the email');

        $this->assertSame(0, $this->call('GET', "/api/comments/posts/{$postId}")->assertOk()->json()['data']['total'] ?? 0);

        $this->call('PATCH', "/api/admin/comments/{$commentId}", ['status' => 'approved'])->assertUnauthorized();
        $this->call('PATCH', "/api/admin/comments/{$commentId}", ['status' => 'approved'], new Identity('u', 't', [], ['posts:manage'], 'session'))->assertForbidden();
        $this->call('PATCH', "/api/admin/comments/{$commentId}", ['status' => 'approved'], new Identity('m', 't', [], ['comments:moderate'], 'session'))->assertOk();

        $thread = $this->call('GET', "/api/comments/posts/{$postId}")->assertOk()->body();
        $this->assertStringContainsString('Great read!', $thread);

        // The same email is now trusted: its next comment goes straight up.
        $second = $this->call('POST', "/api/comments/posts/{$postId}", $this->comment('Me again'))->assertCreated()->json();
        $this->assertSame('approved', $second['status'] ?? $second['data']['status'] ?? null);
    }

    public function test_drafts_and_posts_with_comments_closed_refuse_comments(): void
    {
        $draft  = $this->post(['title' => 'Draft', 'published' => false]);
        $closed = $this->post(['title' => 'Closed', 'commentsEnabled' => false]);

        $this->assertGreaterThanOrEqual(400, $this->call('POST', "/api/comments/posts/{$draft}", $this->comment())->status());
        $this->assertGreaterThanOrEqual(400, $this->call('POST', "/api/comments/posts/{$closed}", $this->comment())->status());
        $this->assertSame([], $this->db->query('SELECT * FROM comments'));
    }

    public function test_script_in_a_comment_is_escaped_in_the_public_thread(): void
    {
        $postId = $this->post();
        $id     = $this->call('POST', "/api/comments/posts/{$postId}", $this->comment('<script>alert(1)</script>'))->assertCreated()->json();
        $id     = $id['id'] ?? $id['data']['id'];
        $this->call('PATCH', "/api/admin/comments/{$id}", ['status' => 'approved'], new Identity('m', 't', [], ['comments:moderate'], 'session'))->assertOk();

        $thread = $this->call('GET', "/api/comments/posts/{$postId}")->assertOk()->body();
        $this->assertStringNotContainsString('<script>', $thread);
    }
}
