<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Comments\Support;

use AlfaCode\LetMigrate\Driver\SQLite\SQLiteDriver;
use AlfaCode\LetMigrate\Driver\SQLite\SQLiteGrammar;
use AlfaCode\LetMigrate\Schema\SchemaBuilder;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;

/**
 * A real SQLite-backed DatabasePort whose schema comes from THE PLUGIN'S OWN
 * MIGRATION, run through LetMigrate — not a hand-copied CREATE TABLE that
 * could drift from what ships. Same shape as the sibling Posts plugin's
 * `tests/Support/SqliteDatabasePort`.
 *
 * LetMigrate's SQLiteDriver keeps its PDO private, so the migration runs
 * against a temporary database FILE and this class opens its own connection
 * to the same file afterwards. The file is removed on destruction.
 */
final class SqliteDatabasePort implements DatabasePort
{
    private const string MIGRATION = __DIR__ . '/../../database/migrations/2026_10_01_000001_create_comments_table.php';

    private \PDO $pdo;

    private string $path;

    /** Every statement run, newest last — lets a test assert what reached the database. */
    public array $statements = [];

    /** When set, the next execute() whose SQL contains this string throws. */
    public ?string $failOn = null;

    /**
     * @param list<string> $extraMigrations Migration files to run FIRST — the
     *        end-to-end test adds the Posts plugin's, so both plugins share one
     *        database exactly as they would in a project.
     */
    public function __construct(array $extraMigrations = [])
    {
        $this->path = (string) tempnam(sys_get_temp_dir(), 'comments-test-');
        $schema     = new SchemaBuilder(new SQLiteDriver($this->path), new SQLiteGrammar());

        foreach ([...$extraMigrations, self::MIGRATION] as $file) {
            (require $file)->up($schema);
        }

        $this->pdo = new \PDO('sqlite:' . $this->path);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public function __destruct()
    {
        unset($this->pdo);
        @unlink($this->path);
    }

    public function query(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function queryOne(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function execute(string $sql, array $params = []): int
    {
        if ($this->failOn !== null && str_contains($sql, $this->failOn)) {
            $this->failOn = null;
            throw new \PDOException('Simulated failure.');
        }

        return $this->run($sql, $params)->rowCount();
    }

    public function upsert(string $table, array $values, array $conflictColumns, ?array $updateColumns = null): int
    {
        throw new \RuntimeException('Not used by CommentRepository.');
    }

    public function lastInsertId(?string $sequence = null): string
    {
        return (string) $this->pdo->lastInsertId();
    }

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollback(): void
    {
        $this->pdo->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    /** @param array<string, mixed> $params */
    private function run(string $sql, array $params): \PDOStatement
    {
        foreach ($params as $key => $value) {
            if (is_bool($value)) {
                $params[$key] = $value ? 1 : 0;
            }
        }

        $this->statements[] = $sql;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt;
    }
}
