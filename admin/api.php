<?php
// admin/api.php — Admin AJAX endpoints
require_once __DIR__ . '/config.php';
require_admin();

$pdo = get_db();
$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

header('Content-Type: application/json; charset=utf-8');

try {
    if ($action === 'stats') {
        // total messages
        $total = (int)$pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn();

        // last 24h messages
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE strftime('%s', time) >= strftime('%s','now','-1 day')");
        $stmt->execute();
        $last24 = (int)$stmt->fetchColumn();

        // active users
        $cut = time() - 40;
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE last_active >= :cut');
        $stmt->execute([':cut' => $cut]);
        $activeUsers = (int)$stmt->fetchColumn();

        // rooms
        $stmt = $pdo->query('SELECT r.name, IFNULL(m.cnt,0) AS count FROM rooms r LEFT JOIN (SELECT room, COUNT(*) AS cnt FROM messages GROUP BY room) m ON r.name = m.room ORDER BY r.name ASC');
        $rooms = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rooms[] = ['name' => $r['name'], 'count' => (int)$r['count']];
        }
        $roomsCount = count($rooms);

        // recent messages
        $stmt = $pdo->query('SELECT id, user, text, time, room, recipient FROM messages ORDER BY id DESC LIMIT 50');
        $recent = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // site settings
        $siteTitle = get_setting('site_title', 'Chat');
        $maxMessages = (int)get_setting('max_messages', 500);

        // css
        $cssPath = realpath(__DIR__ . '/../style.css') ?: (__DIR__ . '/../style.css');
        $css = is_readable($cssPath) ? file_get_contents($cssPath) : '';

        echo json_encode([
            'total_messages' => $total,
            'messages_last24h' => $last24,
            'active_users' => $activeUsers,
            'rooms_count' => $roomsCount,
            'rooms' => $rooms,
            'recent_messages' => $recent,
            'site_title' => $siteTitle,
            'max_messages' => $maxMessages,
            'css' => $css
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'create_room') {
        $name = isset($_POST['name']) ? trim($_POST['name']) : '';
        if ($name === '') throw new Exception('Nome da sala vazio');
        if (mb_strlen($name) > 64) $name = mb_substr($name, 0, 64);
        $stmt = $pdo->prepare('INSERT OR IGNORE INTO rooms (name, created_at) VALUES (:name, :t)');
        $stmt->execute([':name' => $name, ':t' => time()]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'delete_room') {
        $name = isset($_POST['name']) ? trim($_POST['name']) : '';
        if ($name === '') throw new Exception('Nome da sala vazio');
        // delete messages in room
        $stmt = $pdo->prepare('DELETE FROM messages WHERE room = :name');
        $stmt->execute([':name' => $name]);
        // delete typing entries and room record
        $stmt = $pdo->prepare('DELETE FROM typing WHERE room = :name');
        $stmt->execute([':name' => $name]);
        $stmt = $pdo->prepare('DELETE FROM rooms WHERE name = :name');
        $stmt->execute([':name' => $name]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'delete_message') {
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if ($id <= 0) throw new Exception('ID inválido');
        $stmt = $pdo->prepare('DELETE FROM messages WHERE id = :id');
        $stmt->execute([':id' => $id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'edit_site') {
        $siteTitle = isset($_POST['site_title']) ? trim($_POST['site_title']) : '';
        $maxMessages = isset($_POST['max_messages']) ? intval($_POST['max_messages']) : null;
        set_setting('site_title', $siteTitle);
        if ($maxMessages !== null && $maxMessages > 0) {
            set_setting('max_messages', (string)$maxMessages);
            // enforce now: delete older messages if over limit
            // Keep newest N messages
            $stmt = $pdo->prepare('DELETE FROM messages WHERE id NOT IN (SELECT id FROM messages ORDER BY id DESC LIMIT :lim)');
            $stmt->bindValue(':lim', $maxMessages, PDO::PARAM_INT);
            $stmt->execute();
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'edit_css') {
        $css = isset($_POST['css']) ? $_POST['css'] : '';
        $cssPath = realpath(__DIR__ . '/../style.css') ?: (__DIR__ . '/../style.css');
        if (!is_writable(dirname($cssPath)) && file_exists($cssPath) && !is_writable($cssPath)) {
            throw new Exception('Permissão de escrita insuficiente para style.css');
        }
        file_put_contents($cssPath, $css);
        echo json_encode(['ok' => true]);
        exit;
    }

    throw new Exception('Ação inválida');
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}