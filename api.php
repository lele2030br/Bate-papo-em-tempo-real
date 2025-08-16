<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

$dbFile = __DIR__ . '/chat.sqlite';
$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
$now = time();

function get_db($dbFile) {
    $needInit = !file_exists($dbFile);
    try {
        $pdo = new PDO('sqlite:' . $dbFile);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode = WAL;');
        if ($needInit) {
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
            // inserir sala Global padrão
            $stmt = $pdo->prepare('INSERT OR IGNORE INTO rooms (name, created_at) VALUES (:name, :t)');
            $stmt->execute([':name' => 'Global', ':t' => time()]);
        }
        return $pdo;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Falha ao abrir banco: ' . $e->getMessage()]);
        exit;
    }
}

if (!isset($_SESSION['username'])) {
    // allowed actions for non-logged users: none (client should login)
    http_response_code(401);
    echo json_encode(['error' => 'Não autorizado']);
    exit;
}

$me = mb_substr($_SESSION['username'], 0, 32);
$pdo = get_db($dbFile);

// Atualiza usuário (registro de presença)
// Chamamos update em quase todas as ações para manter lista de usuários ativa.
function touch_user($pdo, $me, $current_room = null) {
    $stmt = $pdo->prepare('INSERT INTO users (name, last_active, current_room) VALUES (:name, :t, :room) ON CONFLICT(name) DO UPDATE SET last_active = :t2, current_room = :room2');
    $t = time();
    $stmt->execute([':name' => $me, ':t' => $t, ':room' => $current_room, ':t2' => $t, ':room2' => $current_room]);
}

if ($action === 'get') {
    $last_id = isset($_GET['last_id']) ? intval($_GET['last_id']) : 0;
    $room = isset($_GET['room']) && $_GET['room'] !== '' ? trim($_GET['room']) : null;
    $recipient = isset($_GET['recipient']) && $_GET['recipient'] !== '' ? trim($_GET['recipient']) : null;

    touch_user($pdo, $me, $room);

    $start = time();
    $timeout = 25;

    while (true) {
        try {
            $messages = [];
            if ($recipient) {
                // Private conversation between me and recipient
                $stmt = $pdo->prepare('SELECT id, user, text, time FROM messages WHERE id > :last AND recipient = :rec AND ((user = :me) OR (user = :rec)) ORDER BY id ASC');
                // This ensures messages sent to recipient or to me where participant is either user
                // But better to include both directions:
                $stmt = $pdo->prepare('SELECT id, user, text, time FROM messages WHERE id > :last AND ((recipient = :rec AND user = :me) OR (recipient = :me AND user = :rec)) ORDER BY id ASC');
                $stmt->execute([':last' => $last_id, ':rec' => $recipient, ':me' => $me]);
                $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                // Room messages (room not null)
                $stmt = $pdo->prepare('SELECT id, user, text, time FROM messages WHERE id > :last AND room = :room ORDER BY id ASC');
                $stmt->execute([':last' => $last_id, ':room' => $room]);
                $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // rooms list
            $stmt = $pdo->query('SELECT name FROM rooms ORDER BY name ASC');
            $rooms = array_map(function($r){ return ['name' => $r['name']]; }, $stmt->fetchAll(PDO::FETCH_ASSOC));

            // active users (last_active within 40s)
            $cut = time() - 40;
            $stmt = $pdo->prepare('SELECT name, current_room FROM users WHERE last_active >= :cut ORDER BY name ASC');
            $stmt->execute([':cut' => $cut]);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // typing statuses relevant for view: same room OR private to me or typing to same recipient
            $typingStmt = $pdo->prepare('SELECT user, room, recipient FROM typing WHERE expires >= :now');
            $typingStmt->execute([':now' => time()]);
            $typingAll = $typingStmt->fetchAll(PDO::FETCH_ASSOC);
            $typing = [];
            foreach ($typingAll as $t) {
                if ($recipient) {
                    // private view: show typers who are typing to me and match recipient OR vice-versa
                    if (($t['recipient'] === $me && $t['user'] === $recipient) || ($t['recipient'] === $recipient && $t['user'] === $me)) {
                        $typing[] = $t;
                    }
                } else {
                    // room view: show typers in same room (room field)
                    if ($t['room'] === $room) $typing[] = $t;
                }
            }

            if (count($messages) > 0 || true) {
                // Always return current state (messages may be empty). Long-polling ensures near-real-time for messages.
                echo json_encode([
                    'messages' => $messages,
                    'rooms' => $rooms,
                    'users' => $users,
                    'typing' => $typing
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Erro ao ler mensagens: ' . $e->getMessage()]);
            exit;
        }

        if ((time() - $start) >= $timeout) {
            // return empty state to keep client alive
            echo json_encode(['messages' => [], 'rooms' => $rooms, 'users' => $users, 'typing' => $typing], JSON_UNESCAPED_UNICODE);
            exit;
        }
        usleep(300000);
    }
} elseif ($action === 'send') {
    $text = isset($_POST['text']) ? trim($_POST['text']) : '';
    $room = isset($_POST['room']) && $_POST['room'] !== '' ? trim($_POST['room']) : null;
    $recipient = isset($_POST['recipient']) && $_POST['recipient'] !== '' ? trim($_POST['recipient']) : null;

    if ($text === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Mensagem vazia']);
        exit;
    }
    if (mb_strlen($text) > 1000) {
        $text = mb_substr($text, 0, 1000);
    }

    $timeStr = gmdate('c');

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO messages (user, text, time, room, recipient) VALUES (:user, :text, :time, :room, :recipient)');
        $stmt->execute([
            ':user' => $me,
            ':text' => $text,
            ':time' => $timeStr,
            ':room' => $room,
            ':recipient' => $recipient
        ]);
        $newId = $pdo->lastInsertId();

        // Atualiza usuário presença
        touch_user($pdo, $me, $room);

        // Limita histórico a 500 mensagens
        $pdo->exec('DELETE FROM messages WHERE id NOT IN (SELECT id FROM messages ORDER BY id DESC LIMIT 500);');

        $pdo->commit();
        echo json_encode(['ok' => true, 'id' => (int)$newId], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao salvar mensagem: ' . $e->getMessage()]);
        exit;
    }
} elseif ($action === 'create_room') {
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    if ($name === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Nome da sala vazio']);
        exit;
    }
    if (mb_strlen($name) > 64) $name = mb_substr($name, 0, 64);

    try {
        $stmt = $pdo->prepare('INSERT OR IGNORE INTO rooms (name, created_at) VALUES (:name, :t)');
        $stmt->execute([':name' => $name, ':t' => time()]);
        touch_user($pdo, $me, $name);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao criar sala: ' . $e->getMessage()]);
        exit;
    }
} elseif ($action === 'typing') {
    $isTyping = isset($_POST['typing']) && $_POST['typing'] === '1';
    $room = isset($_POST['room']) && $_POST['room'] !== '' ? trim($_POST['room']) : null;
    $recipient = isset($_POST['recipient']) && $_POST['recipient'] !== '' ? trim($_POST['recipient']) : null;

    try {
        if ($isTyping) {
            $expires = time() + 6; // 6s validade
            $stmt = $pdo->prepare('INSERT INTO typing (user, room, recipient, expires) VALUES (:user, :room, :recipient, :expires) ON CONFLICT(user) DO UPDATE SET room = :room2, recipient = :recipient2, expires = :expires2');
            $stmt->execute([':user' => $me, ':room' => $room, ':recipient' => $recipient, ':expires' => $expires, ':room2' => $room, ':recipient2' => $recipient, ':expires2' => $expires]);
        } else {
            $stmt = $pdo->prepare('DELETE FROM typing WHERE user = :user');
            $stmt->execute([':user' => $me]);
        }
        touch_user($pdo, $me, $room);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro no typing: ' . $e->getMessage()]);
        exit;
    }
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Ação inválida']);
    exit;
}