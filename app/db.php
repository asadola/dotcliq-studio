<?php
declare(strict_types=1);

/** Shared PDO connection (lazy). */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = defined('DB_DSN')
            ? DB_DSN
            : 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, defined('DB_USER') ? DB_USER : null, defined('DB_PASS') ? DB_PASS : null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $pdo->exec("SET time_zone = '+03:00'");
        }
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function rows(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function row(string $sql, array $params = []): ?array
{
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}

function val(string $sql, array $params = [])
{
    $r = q($sql, $params)->fetch(PDO::FETCH_NUM);
    return $r === false ? null : $r[0];
}

function last_id(): int
{
    return (int) db()->lastInsertId();
}
