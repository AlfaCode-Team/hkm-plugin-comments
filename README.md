# hkm-plugin-comments

HKM Kernel plugin providing `comment.discussion` — reader comments on a post
published by the [Posts](https://github.com/AlfaCode-Team/hkm-plugin-posts)
plugin (`post.publishing`).

```
composer require alfacode-team/hkm-plugin-comments
```

## What it owns

- The `comments` table (central + tenant-template migrations — works with or
  without the Tenancy plugin).
- Threaded, moderated comments: pending / approved / spam / trash.
- A spam guard: honeypot, minimum fill time, a signed form token, a max-links
  check, duplicate detection, and a per-IP rate limit.
- Its own HTTP routes and its own two route-filter aliases
  (`comments.throttle`, `comments.moderate`) — no dependency on
  SecurityFilters.

It depends on Posts ONLY through `Plugins\Posts\API\Contracts\PostServiceContract`
and `Plugins\Posts\API\DTO\PostView`. A comment may be submitted only when
`PostServiceContract::find($postId)` returns a view whose `status` is
`'published'` and `commentsEnabled` is `true`.

## Wiring it into a project

```php
->withModules([
    // ...
    \Plugins\Posts\Provider::class,
    \Plugins\Comments\Provider::class,
])
```

`requires: ["database.management", "post.publishing"]` — both module domains
must be registered for Comments to boot.

## Routes

| Method | Path | Purpose | Filter |
|---|---|---|---|
| GET | `/api/comments/form-token` | Mint a signed form token | — |
| GET | `/api/comments/posts/{postId:alphanum}` | A page of a post's thread | — |
| POST | `/api/comments/posts/{postId:alphanum}` | Submit a comment | `comments.throttle` |
| GET | `/api/admin/comments` | Moderation queue | `comments.moderate` |
| GET | `/api/admin/comments/{id:alphanum}` | One comment, any status | `comments.moderate` |
| PATCH | `/api/admin/comments/{id:alphanum}` | Change status (`{"status": "..."}`) | `comments.moderate` |
| PUT | `/api/admin/comments/{id:alphanum}` | Edit the body (`{"body": "..."}`) | `comments.moderate` |
| DELETE | `/api/admin/comments/{id:alphanum}` | Delete (cascades to replies) | `comments.moderate` |
| POST | `/api/admin/comments/bulk` | Bulk status change (`{"ids":[...], "status":"..."}`, at most 200 ids; rows already in that status are left as they are) | `comments.moderate` |

Deliberately **not** nested under `/api/posts` — Posts owns that prefix
(`GET /api/posts/{kind}/{slug}`), and a blog post slugged `comments` would
collide with a nested `/api/posts/{postId}/comments` route.

### `comments.moderate` — the only authorization this plugin performs

A guest or missing `Identity` gets **401**; an `Identity` lacking the
configured permission (`COMMENTS_MODERATE_PERMISSION`, default
`comments:moderate`) gets **403**. `Identity::hasPermission()` already treats
`'*'` as every permission.

The published contract's admin methods (`moderate`, `update`, `delete`,
`bulkModerate`) take `$actorId` as a plain string rather than reading an
`Identity` themselves — the route filter is where 401/403 is decided, so a CLI
tool or another module can call these methods directly without an HTTP
request in flight.

## `CommentServiceContract`

```php
formToken(): string;
submit(string $postId, array $input, ?Identity $identity, string $ip, ?string $userAgent): CommentView;
thread(string $postId, int $page = 1, int $perPage = 20): CommentPage;
count(string $postId): int;
counts(array $postIds): array;                 // one query, for a listing page
list(?string $status, ?string $postId, ?string $search, int $page, int $perPage): CommentAdminPage;
find(string $id): ?CommentAdminView;
moderate(string $id, string $status, string $actorId): ?CommentAdminView;
update(string $id, string $body, string $actorId): ?CommentAdminView;
delete(string $id): bool;                      // deletes the comment and every reply beneath it
bulkModerate(array $ids, string $status, string $actorId): int;
```

### `submit()` input keys

| Key | Required | Notes |
|---|---|---|
| `parentId` | no | Public id of the comment being replied to |
| `authorName` | yes, unless `Identity` supplies one | ≤120 chars |
| `authorEmail` | yes, unless `Identity` supplies one | Valid email, ≤190 chars |
| `authorUrl` | no | Must start with `http://` or `https://` |
| `body` | yes | Plain text, 1–5000 chars |
| `website_url` | no | **Honeypot** — must be empty from a real visitor |
| `startedAt` | yes | The token from `formToken()` |

When `Identity` is present and not a guest, `authorUserId` is taken from it,
and `authorName`/`authorEmail` default to its `fullName`/`email` when the
input omits them.

## What is public vs admin

`CommentView` (public — `thread()`, and the DTO `submit()` returns): id,
postId, parentId, depth, authorName, authorUrl, **HTML-escaped** body, status,
createdAt, nested `replies`. **Never** the author's email, IP hash, or user
agent.

`CommentAdminView` (moderation — `list()`/`find()`/`moderate()`/`update()`):
everything above plus authorUserId, authorEmail, raw body, ipHash, userAgent,
approvedAt, updatedAt. Never the raw IP — only its salted SHA-256 hash ever
existed past the request that wrote it.

## Spam guard (all in `CommentService::submit()`, each independently testable)

| Check | Outcome when triggered |
|---|---|
| Honeypot (`website_url` non-empty) | Silent — status becomes `spam`, request still succeeds (never tips off a bot) |
| Submitted under `COMMENTS_MIN_SECONDS` after the form token was minted | `422 ValidationException` (`startedAt`) |
| Form token older than 24h, or malformed/tampered | `422 ValidationException` (`startedAt`) |
| Unknown `parentId`, or belonging to a different post | `422 ValidationException` (`parentId`) |
| Same post + email + body within 10 minutes | `422 ValidationException` (`body`) |
| More than `COMMENTS_MAX_LINKS` links in the body | Silent — status becomes `spam` |
| Over `COMMENTS_RATE_PER_MINUTE` submissions from one IP hash in a minute | `429` either way: the `comments.throttle` HTTP filter answers before the service runs, and the service itself throws `SecurityException` code 429 (`comment.submit.rate_limited`) for callers that bypass HTTP |

The per-IP rate limit and the HTTP-layer throttle are **both** enforced,
deliberately: a caller that invokes `CommentServiceContract::submit()`
directly (bypassing HTTP) is still protected by the service's own check.
Either one is a no-op with no `CachePort` bound.

## Moderation status

`COMMENTS_MODERATION`:

- `all` — every comment starts `pending`.
- `first` (default) — auto-approved once that email has at least one
  `approved` comment **anywhere on this installation** (not scoped to the one
  post) — the common "trusted commenter" rule.
- `none` — every comment is auto-approved.

Whatever the policy, an `Identity` holding `COMMENTS_MODERATE_PERMISSION` is
always auto-approved (a moderator posting is never held for their own
review).

## Threading and the depth clamp

`COMMENTS_MAX_DEPTH` (default `3`) is the deepest a reply nests. A reply to a
comment already AT that depth does not nest further — it attaches to that
comment's own parent instead, staying at the maximum depth, rather than being
rejected or silently flattened to a top-level comment.

`thread()` paginates top-level comments; every approved reply (at any depth)
is loaded and nested under its parent in the same call — replies are never
paginated separately.

## Events

- `comment.submitted` (`CommentSubmittedIntegrationEvent`) — commentId,
  postId, status, occurredAt. Dispatched after every successful `submit()`,
  whatever the resulting status.
- `comment.approved` (`CommentApprovedIntegrationEvent`) — commentId, postId,
  occurredAt. Dispatched at most once per comment per approval: an
  auto-approved submission, or a `moderate()`/`bulkModerate()` transition INTO
  `approved`. Re-approving an already-approved comment emits nothing.

Neither event carries the email, the body, or the IP.

## Configuration

| Var | Type | Default | Notes |
|---|---|---|---|
| `COMMENTS_SECRET` | string | — | HMAC key for the form token and the IP salt. Falls back to `APP_KEY` |
| `APP_KEY` | string | — | Shared application key (read as the fallback above) |
| `COMMENTS_MODERATION` | string | `first` | `all` \| `first` \| `none` |
| `COMMENTS_MODERATE_PERMISSION` | string | `comments:moderate` | Checked by the `comments.moderate` route filter |
| `COMMENTS_MIN_SECONDS` | int | `3` | Minimum fill time before a submission is accepted |
| `COMMENTS_MAX_LINKS` | int | `2` | Over this many links in the body → `spam` |
| `COMMENTS_MAX_DEPTH` | int | `3` | Deepest reply nesting level |
| `COMMENTS_RATE_PER_MINUTE` | int | `5` | Per-IP-hash submissions per minute (needs a `CachePort`; skipped silently without one) |

## Local development

Posts is not yet on GitHub, so `composer.json`'s `vcs` repository for it will
not resolve until it is published. For local testing, a gitignored
`composer.local.json` adds `path` repositories for the kernel and
`hkm-plugin-posts`:

```
COMPOSER=composer.local.json composer update
vendor/bin/phpunit
```

The test suite uses a hand-written fake (`tests/Support/FakePostService`)
implementing `PostServiceContract` rather than the real Posts service, so it
has no dependency on Posts' internals — only its published contract and DTO.
