<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Comments;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use PHPUnit\Framework\TestCase;
use Plugins\Comments\Infrastructure\Http\Stages\CommentModerateStage;

/**
 * The ONLY authorization check this plugin's HTTP surface performs — see
 * module.json: the whole `/api/admin/comments` group carries this filter.
 */
final class CommentModerateStageTest extends TestCase
{
    private const string PASSED = 'PASSED_THROUGH';

    protected function setUp(): void
    {
        putenv('COMMENTS_MODERATE_PERMISSION');
    }

    public function test_a_request_with_no_identity_is_unauthorized(): void
    {
        $stage    = new CommentModerateStage();
        $request  = Request::build('GET', '/api/admin/comments');
        $response = $stage->handle($request, $this->next());

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_a_guest_identity_is_unauthorized(): void
    {
        $stage    = new CommentModerateStage();
        $request  = Request::build('GET', '/api/admin/comments')->withIdentity(Identity::guest());
        $response = $stage->handle($request, $this->next());

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_an_identity_without_the_permission_is_forbidden(): void
    {
        $stage    = new CommentModerateStage();
        $identity = Identity::asUser('user-1', permissions: ['some:other']);
        $request  = Request::build('GET', '/api/admin/comments')->withIdentity($identity);
        $response = $stage->handle($request, $this->next());

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_an_identity_with_the_permission_passes_through(): void
    {
        $stage    = new CommentModerateStage();
        $identity = Identity::asUser('user-1', permissions: ['comments:moderate']);
        $request  = Request::build('GET', '/api/admin/comments')->withIdentity($identity);
        $response = $stage->handle($request, $this->next());

        self::assertSame(self::PASSED, $response->body());
    }

    public function test_a_wildcard_permission_passes_through(): void
    {
        $stage    = new CommentModerateStage();
        $identity = Identity::asAdmin();
        $request  = Request::build('GET', '/api/admin/comments')->withIdentity($identity);
        $response = $stage->handle($request, $this->next());

        self::assertSame(self::PASSED, $response->body());
    }

    public function test_a_custom_permission_name_is_honoured(): void
    {
        putenv('COMMENTS_MODERATE_PERMISSION=custom:perm');

        $stage    = new CommentModerateStage();
        $identity = Identity::asUser('user-1', permissions: ['custom:perm']);
        $request  = Request::build('GET', '/api/admin/comments')->withIdentity($identity);
        $response = $stage->handle($request, $this->next());

        self::assertSame(self::PASSED, $response->body());

        putenv('COMMENTS_MODERATE_PERMISSION');
    }

    private function next(): callable
    {
        return static fn(Request $r): Response => Response::text(self::PASSED);
    }
}
