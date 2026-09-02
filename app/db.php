<?php
/**
 * Single shared PDO connection.
 */
function db(bool $reset = false): PDO
{
    static $pdo = null;
    if ($reset) {
        $pdo = null;
    }
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        $pdo = null;
        throw new RuntimeException(
            APP_DEBUG ? 'Database connection failed: ' . $e->getMessage()
                      : 'The database is unavailable.',
            0, $e
        );
    }

    // Keep MySQL's session clock aligned with the app so NOW() is UTC too.
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

/**
 * Whether a failure means the connection died rather than the query being bad.
 *
 * MySQL closes an idle connection after wait_timeout, which on shared hosting
 * can be a couple of minutes. Publishing an Instagram post takes longer than
 * that on a bad day - the container has to be polled until it reports FINISHED
 * - so the connection is regularly dead by the time the result is written back.
 */
function db_lost_connection(Throwable $e): bool
{
    $msg = $e->getMessage();
    foreach (['server has gone away', 'Lost connection', 'no connection to the server',
              'Error while sending', 'broken pipe'] as $needle) {
        if (stripos($msg, $needle) !== false) {
            return true;
        }
    }
    return false;
}

/** Throw away the current handle so the next db() call dials again. */
function db_reset(): void
{
    db(true);
}

/**
 * Make sure the connection is alive, reconnecting if it is not.
 *
 * Call this after anything slow that does not touch the database - a long HTTP
 * request to a social network, image processing - and before the writes that
 * record the result. Reconnecting here rather than retrying the write later
 * matters: the write may be the only record that a post actually went out.
 */
function db_ping(): void
{
    try {
        db()->query('SELECT 1');
        return;
    } catch (Throwable $e) {
        if (!db_lost_connection($e)) {
            throw $e;
        }
    }
    // A transaction cannot survive the reconnect, so anything uncommitted is
    // already lost either way; reconnecting at least lets the caller continue.
    db_reset();
    db();
}

/**
 * Run a read, reconnecting once if the connection had died.
 *
 * Only reads retry automatically. Replaying a write after a lost connection is
 * not safe - the server may have applied it before the link dropped - so
 * writes rely on db_ping() being called before them instead.
 */
function db_read(callable $fn)
{
    try {
        return $fn();
    } catch (Throwable $e) {
        if (!db_lost_connection($e) || db()->inTransaction()) {
            throw $e;
        }
    }
    db_reset();
    return $fn();
}

function db_one(string $sql, array $params = []): ?array
{
    return db_read(function () use ($sql, $params) {
        $st = db()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    });
}

function db_all(string $sql, array $params = []): array
{
    return db_read(function () use ($sql, $params) {
        $st = db()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    });
}

function db_run(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function db_value(string $sql, array $params = [])
{
    return db_read(function () use ($sql, $params) {
        $st = db()->prepare($sql);
        $st->execute($params);
        return $st->fetchColumn();
    });
}

/** True when the schema has been imported. */
function db_installed(): bool
{
    try {
        db()->query('SELECT 1 FROM users LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** Connect without throwing - used by the installer's status checks. */
function db_can_connect(): bool
{
    try {
        db();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}
