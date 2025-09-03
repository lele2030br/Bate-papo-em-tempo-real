<?php
// admin/config.php
// Shared helpers for the admin panel.

session_start();

$dbFile = realpath(__DIR__ . '/../chat.sqlite') ?: (__DIR__ . '/../chat.sqlite');

function get_db() {
    global $dbFile;
    $needInit = !file_exists($dbFile);
    try {
        $pdo = new PDO('sqlite:' . $dbFile);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode = WAL;');
        if ($needInit) {
            // If DB not present, create minimal schema used by chat + admin settings
            $pdo->exec('CREATE TABLE IF NOT EXISTS messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user TEXT NOT NULL,
                text TEXT NOT NULL,
                time TEXT NOT NULL,
                room TEXT,
                recipient TEXT
            );');
            $pdo->exec('CREATE TABLE IF NOT EXISTS rooms (
                name TEXT PRIMARY KEY,
                created_at INTEGER NOT NULL
            );');
            $pdo->exec('CREATE TABLE IF NOT EXISTS users (
                name TEXT PRIMARY KEY,
                last_active INTEGER NOT NULL,
                current_room TEXT
            );');
            $pdo->exec('CREATE TABLE IF NOT EXISTS typing (
                user TEXT PRIMARY KEY,
                room TEXT,
                recipient TEXT,
                expires INTEGER NOT NULL
            );');
            $pdo->exec('CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT
            );');
            $stmt = $pdo->prepare('INSERT OR IGNORE INTO rooms (name, created_at) VALUES (:name, :t)');
            $stmt->execute([':name' => 'Global', ':t' => time()]);
        } else {
            // ensure settings table exists
            $pdo->exec('CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT);');
        }
        return $pdo;
    } catch (PDOException $e) {
        header('Content-Type: application/json; charset=utf-8', true, 500);
        echo json_encode(['error' => 'Falha ao abrir banco: ' . $e->getMessage()]);
        exit;
    }
}

function get_setting($key, $default = null) {
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT value FROM settings WHERE key = :k LIMIT 1');
    $stmt->execute([':k' => $key]);
    $v = $stmt->fetchColumn();
    return $v === false ? $default : $v;
}

function set_setting($key, $value) {
    $pdo = get_db();
    $stmt = $pdo->prepare('INSERT INTO settings (key, value) VALUES (:k, :v) ON CONFLICT(key) DO UPDATE SET value = :v2');
    $stmt->execute([':k' => $key, ':v' => $value, ':v2' => $value]);
}

function require_admin() {
    if (empty($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
        header('Location: login.php');
        exit;
    }
}