<?php
// Small mysqli wrapper so every query uses prepared statements.

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function db(): mysqli
{
    static $conn = null;
    if ($conn === null) {
        try {
            $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
        } catch (mysqli_sql_exception $e) {
            http_response_code(500);
            die('<h3 style="font-family:sans-serif">Cannot connect to the database "' . htmlspecialchars(DB_NAME) .
                '". Start MySQL in XAMPP and import the files in the /database folder.</h3>');
        }
        $conn->set_charset('utf8mb4');
        $conn->query("SET time_zone = '+08:00'");
    }
    return $conn;
}

/** Run a query with ? placeholders. Returns the mysqli_stmt. */
function db_query(string $sql, array $params = []): mysqli_stmt
{
    $stmt = db()->prepare($sql);
    if ($params) {
        $types = '';
        foreach ($params as $p) {
            $types .= is_int($p) ? 'i' : (is_float($p) ? 'd' : 's');
        }
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt;
}

function db_all(string $sql, array $params = []): array
{
    $res = db_query($sql, $params)->get_result();
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

function db_one(string $sql, array $params = []): ?array
{
    $res = db_query($sql, $params)->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    return $row ?: null;
}

function db_value(string $sql, array $params = [])
{
    $row = db_one($sql, $params);
    return $row ? reset($row) : null;
}

/** INSERT/UPDATE/DELETE. Returns affected rows. */
function db_exec(string $sql, array $params = []): int
{
    $stmt = db_query($sql, $params);
    return $stmt->affected_rows;
}

function db_insert_id(): int
{
    return (int) db()->insert_id;
}
