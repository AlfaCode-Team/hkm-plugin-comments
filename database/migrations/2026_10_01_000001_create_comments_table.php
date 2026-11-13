<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/**
 * Comments — reader comments on a `Plugins\Posts` post.
 *
 * Shipped IDENTICALLY in database/migrations (central) and
 * database/tenant-template, so the plugin works on a project with or without
 * Tenancy: the rows live on whichever connection the request is routed to.
 * Keep the two copies byte-identical (MigrationTest enforces it).
 *
 * `public_id` is a ULID and the only id a caller sees. `post_id` holds the
 * OWNING post's public_id (Posts plugin), and `parent_id` holds another
 * comment's public_id — both soft references, no FK: this plugin's domain
 * knows nothing about the Posts schema, and a FK across plugin-owned tables
 * would couple two migrations that must be independently installable.
 *
 * `status` is a plain varchar, not a native ENUM column: LetMigrate's ENUM
 * support varies by driver (notably SQLite, which has none), and a varchar
 * with the set validated in the Domain (`CommentStatus`) is what the sibling
 * Posts/Announcements plugins already do for the same reason.
 *
 * Indexes follow the queries in CommentRepository:
 *   uniq (public_id)                      every lookup by id
 *   (post_id, status, created_at)         a post's thread / count
 *   (status, created_at)                  the moderation queue
 *   (parent_id)                           reply nesting, cascade delete
 *   (author_email, status)                the "first" moderation policy
 */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('comments')) {
            return;
        }

        $schema->create('comments', static function ($t) {
            $t->id();
            $t->ulid('public_id')->charset('ascii')->collation('ascii_bin')
                ->comment('public identifier; never expose the internal id');

            $t->char('post_id', 26)->charset('ascii')->collation('ascii_bin')
                ->comment('Posts plugin public_id; no FK — cross-plugin soft reference');
            $t->char('parent_id', 26)->charset('ascii')->collation('ascii_bin')->nullable()
                ->comment('public_id of the comment being replied to; no FK');
            $t->unsignedTinyInteger('depth')->default(0)
                ->comment('0..COMMENTS_MAX_DEPTH; clamped by the service, never by this schema');

            $t->string('author_user_id', 64)->nullable()->comment('soft reference to a user id; no FK');
            $t->string('author_name', 120);
            $t->string('author_email', 190)->comment('never exposed in a public view');
            $t->string('author_url', 500)->nullable()->comment('http(s) only');

            $t->text('body')->comment('plain text, <= 5000 chars; stored raw, no HTML accepted');
            $t->string('status', 10)->default('pending')->comment('pending | approved | spam | trash');

            $t->char('ip_hash', 64)->comment('sha256(ip + salt); the raw IP is never stored');
            $t->string('user_agent', 255)->nullable();

            $t->timestamps();
            $t->dateTime('approved_at')->nullable();

            $t->unique(['public_id'], 'uniq_comment_public_id');
            $t->index(['post_id', 'status', 'created_at'], 'idx_comment_post_status_created');
            $t->index(['status', 'created_at'], 'idx_comment_status_created');
            $t->index(['parent_id'], 'idx_comment_parent');
            $t->index(['author_email', 'status'], 'idx_comment_email_status');

            $t->engine('InnoDB');
            $t->charset('utf8mb4');
            $t->collation('utf8mb4_0900_ai_ci');
            $t->rowFormat('DYNAMIC');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('comments');
    }
};
