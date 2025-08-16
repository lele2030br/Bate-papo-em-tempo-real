<?php
session_start();

// login simples
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'])) {
    $username = trim($_POST['username']);
    if ($username === '') {
        $error = 'Digite um nome de usuário válido.';
    } else {
        $_SESSION['username'] = mb_substr($username, 0, 32);
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

$username = isset($_SESSION['username']) ? $_SESSION['username'] : null;
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Chat em tempo real - PHP + SQLite (Salas, Privado, Digitando)</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="stylesheet" href="style.css">
</head>
<body>
<?php if (!$username): ?>
    <div class="login-wrap">
        <h1>Entrar no chat</h1>
        <?php if (!empty($error)): ?>
            <div class="error"><?=htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></div>
        <?php endif; ?>
        <form method="post" action="">
            <input type="text" name="username" placeholder="Seu nome" maxlength="32" required autofocus>
            <button type="submit">Entrar</button>
        </form>
    </div>
<?php else: ?>
    <div class="app">
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2>Salas</h2>
                <button id="createRoomBtn" title="Criar sala">+</button>
            </div>
            <ul id="roomsList" class="rooms-list">
                <!-- salas via JS -->
            </ul>

            <div class="sidebar-header">
                <h2>Usuários</h2>
            </div>
            <ul id="usersList" class="users-list">
                <!-- usuários via JS -->
            </ul>

            <div class="me">
                Você: <strong><?=htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></strong>
                <a class="logout" href="logout.php">Sair</a>
            </div>
        </aside>

        <main class="chat-area">
            <header class="chat-header">
                <h1 id="chatTitle">Sala: Global</h1>
                <div id="subtitle" class="subtitle"></div>
            </header>

            <main id="messages" class="messages" aria-live="polite"></main>

            <div id="typingIndicator" class="typing"></div>

            <form id="sendForm" class="send-form">
                <textarea id="messageInput" placeholder="Digite sua mensagem..." maxlength="1000" required></textarea>
                <button type="submit">Enviar</button>
            </form>
        </main>
    </div>

    <!-- criar sala modal simples -->
    <div id="roomModal" class="modal" aria-hidden="true">
        <div class="modal-content">
            <h3>Criar nova sala</h3>
            <form id="createRoomForm">
                <input type="text" id="newRoomName" placeholder="Nome da sala" maxlength="64" required>
                <div class="modal-actions">
                    <button type="submit">Criar</button>
                    <button type="button" id="cancelCreateRoom">Cancelar</button>
                </div>
            </form>
            <div id="createRoomError" class="error"></div>
        </div>
    </div>

    <script>
    // Utilitários
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }

    const myUser = <?=json_encode($username, JSON_UNESCAPED_UNICODE)?>;
    let currentRoom = 'Global'; // sala padrão
    let currentRecipient = null; // quando em privado, contém o nome do outro usuário
    let lastId = 0;
    let polling = true;

    const roomsListEl = document.getElementById('roomsList');
    const usersListEl = document.getElementById('usersList');
    const messagesEl = document.getElementById('messages');
    const form = document.getElementById('sendForm');
    const input = document.getElementById('messageInput');
    const chatTitle = document.getElementById('chatTitle');
    const subtitleEl = document.getElementById('subtitle');
    const typingIndicatorEl = document.getElementById('typingIndicator');

    // Modal
    const roomModal = document.getElementById('roomModal');
    const createRoomBtn = document.getElementById('createRoomBtn');
    const createRoomForm = document.getElementById('createRoomForm');
    const newRoomName = document.getElementById('newRoomName');
    const cancelCreateRoom = document.getElementById('cancelCreateRoom');
    const createRoomError = document.getElementById('createRoomError');

    function openModal() { roomModal.style.display = 'flex'; roomModal.setAttribute('aria-hidden', 'false'); newRoomName.focus(); }
    function closeModal() { roomModal.style.display = 'none'; roomModal.setAttribute('aria-hidden', 'true'); createRoomError.textContent = ''; newRoomName.value = ''; }

    createRoomBtn.addEventListener('click', openModal);
    cancelCreateRoom.addEventListener('click', closeModal);
    createRoomForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        const name = newRoomName.value.trim();
        if (!name) return;
        try {
            const formData = new URLSearchParams();
            formData.append('name', name);
            const resp = await fetch('api.php?action=create_room', {
                method: 'POST',
                body: formData,
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
            });
            const data = await resp.json();
            if (!resp.ok) {
                createRoomError.textContent = data.error || 'Erro ao criar sala';
                return;
            }
            closeModal();
            // forçar reload de salas
            lastId = 0; // para receber histórico se trocou
        } catch (err) {
            createRoomError.textContent = 'Erro de rede';
        }
    });

    function setActiveRoom(roomName) {
        currentRoom = roomName;
        currentRecipient = null;
        chatTitle.textContent = 'Sala: ' + roomName;
        subtitleEl.textContent = '';
        messagesEl.innerHTML = '';
        lastId = 0;
    }

    function setPrivateChat(otherUser) {
        currentRecipient = otherUser;
        // Using null room to indicate private conversation
        chatTitle.textContent = 'Privado com: ' + otherUser;
        subtitleEl.textContent = 'Mensagens privadas';
        messagesEl.innerHTML = '';
        lastId = 0;
    }

    function appendMessage(msg) {
        const item = document.createElement('div');
        item.className = 'message';
        const time = new Date(msg.time).toLocaleTimeString();
        const metaUser = escapeHtml(msg.user);
        const text = escapeHtml(msg.text);
        let metaHtml = '<div class="meta"><span class="user">'+metaUser+'</span> <span class="time">'+escapeHtml(time)+'</span></div>';
        item.innerHTML = metaHtml + '<div class="text">' + text + '</div>';
        messagesEl.appendChild(item);
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function renderRooms(rooms, active) {
        roomsListEl.innerHTML = '';
        rooms.forEach(r => {
            const li = document.createElement('li');
            li.className = (r.name === active) ? 'room active' : 'room';
            li.textContent = r.name;
            li.addEventListener('click', () => setActiveRoom(r.name));
            roomsListEl.appendChild(li);
        });
    }

    function renderUsers(users) {
        usersListEl.innerHTML = '';
        users.forEach(u => {
            if (u.name === myUser) return;
            const li = document.createElement('li');
            li.className = 'user';
            li.innerHTML = '<span class="name">'+escapeHtml(u.name)+'</span>' + (u.current_room ? (' <span class="small">('+escapeHtml(u.current_room)+')</span>') : '');
            li.addEventListener('click', () => setPrivateChat(u.name));
            usersListEl.appendChild(li);
        });
    }

    // Polling principal (long-polling)
    async function poll() {
        while (polling) {
            try {
                let params = new URLSearchParams();
                params.append('last_id', lastId);
                params.append('room', currentRecipient ? '' : (currentRoom || 'Global'));
                if (currentRecipient) params.append('recipient', currentRecipient);
                const resp = await fetch('api.php?action=get&' + params.toString(), {cache: 'no-store'});
                if (!resp.ok) {
                    await new Promise(r => setTimeout(r, 2000));
                    continue;
                }
                const data = await resp.json();
                // messages
                if (Array.isArray(data.messages) && data.messages.length > 0) {
                    data.messages.forEach(m => {
                        appendMessage(m);
                        if (m.id > lastId) lastId = m.id;
                    });
                }
                // rooms
                if (Array.isArray(data.rooms)) {
                    renderRooms(data.rooms, currentRoom);
                }
                // users
                if (Array.isArray(data.users)) {
                    renderUsers(data.users);
                }
                // typing
                if (Array.isArray(data.typing) && data.typing.length > 0) {
                    // Show typing (filter out myself)
                    const others = data.typing.filter(t => t.user !== myUser);
                    if (others.length > 0) {
                        typingIndicatorEl.textContent = others.map(o => o.user).join(', ') + (others.length === 1 ? ' está digitando...' : ' estão digitando...');
                    } else {
                        typingIndicatorEl.textContent = '';
                    }
                } else {
                    typingIndicatorEl.textContent = '';
                }
                // loop imediato (server may block until something)
            } catch (err) {
                console.error('Erro no polling:', err);
                await new Promise(r => setTimeout(r, 2000));
            }
        }
    }

    // Envio de mensagem
    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        const text = input.value.trim();
        if (!text) return;
        const payload = new URLSearchParams();
        payload.append('text', text);
        if (currentRecipient) {
            payload.append('recipient', currentRecipient);
        } else {
            payload.append('room', currentRoom || 'Global');
        }

        try {
            const resp = await fetch('api.php?action=send', {
                method: 'POST',
                body: payload,
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
            });
            if (!resp.ok) {
                const err = await resp.json().catch(() => null);
                alert((err && err.error) ? err.error : 'Erro ao enviar mensagem.');
                return;
            }
            input.value = '';
            input.focus();
            // message will be received via polling
            sendTyping(false); // stop typing
        } catch (err) {
            console.error('Erro ao enviar:', err);
            alert('Erro ao enviar mensagem.');
        }
    });

    // Typing indicator: debounce
    let typingTimer = null;
    let typingState = false;
    input.addEventListener('input', function() {
        if (!typingState) {
            sendTyping(true);
            typingState = true;
        }
        clearTimeout(typingTimer);
        typingTimer = setTimeout(() => {
            sendTyping(false);
            typingState = false;
        }, 2500);
    });

    async function sendTyping(isTyping) {
        try {
            const payload = new URLSearchParams();
            payload.append('typing', isTyping ? '1' : '0');
            if (currentRecipient) payload.append('recipient', currentRecipient);
            else payload.append('room', currentRoom || 'Global');

            await fetch('api.php?action=typing', {
                method: 'POST',
                body: payload,
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
            });
        } catch (err) {
            // ignore
        }
    }

    // iniciar valores
    setActiveRoom('Global');
    poll();
    </script>
<?php endif; ?>
</body>
</html>