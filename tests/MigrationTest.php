<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Comments;

use PHPUnit\Framework\TestCase;
use Tests\Unit\Plugins\Comments\Support\SqliteDatabasePort;

/**
 * The migration ships twice — central and tenant-template — so a project gets
 * the same schema with or without Tenancy. Nothing but this test stops the
 * two copies drifting apart.
 */
final class MigrationTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../database';

    public function test_central_and_tenant_migrations_are_identical(): void
    {
        $central = glob(self::ROOT . '/migrations/*.php') ?: [];
        $tenant  = glob(self::ROOT . '/tenant-template/*.php') ?: [];

        $this->assertNotEmpty($central);
        $this->assertSame(array_map('basename', $central), array_map('basename', $tenant));

        foreach ($central as $i => $file) {
            $this->assertFileEquals($file, $tenant[$i], basename($file) . ' differs between the two copies');
        }
    }

    public function test_migration_creates_the_table_with_its_unique_key(): void
    {
        $db = new SqliteDatabasePort();

        $this->assertNotNull($db->queryOne("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'comments'"));

        $insert = "INSERT INTO comments
                    (public_id, post_id, parent_id, depth, author_name, author_email, body, status, ip_hash, created_at, updated_at)
                   VALUES
                    (:id, '01J00000000000000000000POS', NULL, 0, 'Jane', 'jane@example.com', 'Hi', 'approved', :hash, '2026-01-01 00:00:00', '2026-01-01 00:00:00')";

        $db->execute($insert, ['id' => '01J00000000000000000000001', 'hash' => str_repeat('a', 64)]);

        // Same public_id again — must violate uniq_comment_public_id.
        $this->expectException(\PDOException::class);
        $db->execute($insert, ['id' => '01J00000000000000000000001', 'hash' => str_repeat('a', 64)]);
    }
}
