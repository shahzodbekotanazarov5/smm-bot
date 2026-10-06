<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection === null) {
            $host = Config::get('db.host');
            $port = Config::get('db.port', 3306);
            $name = Config::get('db.name');

            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];

            if (!in_array($host, ['127.0.0.1', 'localhost'], true) || getenv('DB_SSL') === 'true') {
                if (file_exists('/etc/ssl/certs/ca-certificates.crt')) {
                    $options[PDO::MYSQL_ATTR_SSL_CA] = '/etc/ssl/certs/ca-certificates.crt';
                }
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
            }

            try {
                self::$connection = new PDO($dsn, Config::get('db.user'), Config::get('db.pass'), $options);
            } catch (PDOException $e) {
                Logger::error('Database connection failed: ' . $e->getMessage());
                throw $e;
            }

            self::alignSessionTimeZone(self::$connection);
        }

        return self::$connection;
    }

    /**
     * Makes MySQL's NOW()/CURRENT_TIMESTAMP agree with PHP's configured
     * timezone (bootstrap.php calls date_default_timezone_set()). Without
     * this, a MySQL server running in UTC (the common default, including
     * most shared-hosting and Docker images) while PHP runs in e.g.
     * Asia/Tashkent (+05:00) produces a multi-hour skew between
     * app-computed "now" and DB-stored timestamps — which silently broke
     * every multi-step conversation (SessionState) during testing, since a
     * just-written state row looked hours "old" and was treated as stale
     * the very next message. Named timezones aren't reliably available on
     * shared hosting (they need the mysql.time_zone tables populated), so
     * this uses a fixed numeric UTC offset instead, and fails soft (some
     * restrictive hosts block SET SESSION) — SessionState/UserService also
     * do their own timezone-agnostic SQL-side comparisons as a backstop.
     */
    private static function alignSessionTimeZone(PDO $pdo): void
    {
        try {
            $tz = new \DateTimeZone((string) Config::get('timezone', 'UTC'));
            $offsetSeconds = $tz->getOffset(new \DateTime('now', $tz));
            $sign = $offsetSeconds < 0 ? '-' : '+';
            $offsetSeconds = abs($offsetSeconds);
            $offset = sprintf('%s%02d:%02d', $sign, intdiv($offsetSeconds, 3600), intdiv($offsetSeconds % 3600, 60));

            $pdo->exec("SET time_zone = '{$offset}'");
        } catch (Throwable $e) {
            Logger::warning('Could not set MySQL session time_zone: ' . $e->getMessage());
        }
    }

    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);

        return $stmt;
    }

    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    public static function execute(string $sql, array $params = []): int
    {
        return self::query($sql, $params)->rowCount();
    }

    public static function lastInsertId(): int
    {
        return (int) self::connection()->lastInsertId();
    }

    /**
     * Runs $fn inside a transaction. $fn receives the PDO instance and its
     * return value is passed through. Any exception rolls back and rethrows.
     */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::connection();

        if ($pdo->inTransaction()) {
            // Nested calls just run inline; only the outermost call owns the transaction.
            return $fn($pdo);
        }

        $pdo->beginTransaction();

        try {
            $result = $fn($pdo);
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Non-blocking named lock (MySQL GET_LOCK), released automatically when
     * the connection closes even on a fatal error/crash. Returns true if acquired.
     */
    public static function tryLock(string $name, int $timeoutSeconds = 0): bool
    {
        $row = self::fetchOne('SELECT GET_LOCK(:name, :timeout) AS locked', [
            'name' => $name,
            'timeout' => $timeoutSeconds,
        ]);

        return $row !== null && (int) $row['locked'] === 1;
    }

    public static function releaseLock(string $name): void
    {
        self::query('SELECT RELEASE_LOCK(:name)', ['name' => $name]);
    }
}
