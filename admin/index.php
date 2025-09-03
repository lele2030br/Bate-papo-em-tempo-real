<?php
// admin/index.php — Dashboard UI
require_once __DIR__ . '/config.php';
require_admin();
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Painel Administrativo — Chat</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="admin-shell">
    <header class="admin-header">
        <h1>Painel Admin</h1>
        <div class="admin-actions">
            <span class="who">Olá, <?=htmlspecialchars($_SESSION['admin_user'] ?? 'admin')?></span>
            <a class="btn" href="logout.php">Sair</a>
        </div>
    </header>

    <main class="admin-main">
        <section class="panel stats">
            <h2>Visão geral</h2>
            <div id="statsArea">Carregando...</div>
        </section>

        <section class="panel rooms">
            <h2>Salas</h2>
            <div class="panel-actions">
                <input id="newRoomName" placeholder="Nova sala" maxlength="64">
                <button id="createRoomBtn">Criar sala</button>
            </div>
            <div id="roomsArea">Carregando...</div>
        </section>

        <section class="panel messages">
            <h2>Mensagens recentes</h2>
            <div id="messagesArea">Carregando...</div>
        </section>

        <section class="panel site-edit">
            <h2>Editar site / configurações</h2>
            <form id="siteForm">
                <label>Título do site
                    <input type="text" id="siteTitle" name="siteTitle" maxlength="120">
                </label>
                <label>Limite de histórico (mensagens)
                    <input type="number" id="maxMessages" name="maxMessages" min="50" max="5000">
                </label>
                <div class="form-actions">
                    <button type="submit">Salvar configurações</button>
                </div>
            </form>

            <h3>Editar CSS (style.css)</h3>
            <textarea id="cssEditor" rows="12" placeholder="Carregando CSS..."></textarea>
            <div class="form-actions">
                <button id="saveCssBtn">Salvar CSS</button>
            </div>

            <div id="adminMessage" style="margin-top:12px;color:#b00"></div>
        </section>
    </main>
</div>

<script>
// Helpers
function elq(q) { return document.querySelector(q); }
function elAll(q) { return Array.from(document.querySelectorAll(q)); }
function escapeHtml(s){ const d=document.createElement('div'); d.appendChild(document.createTextNode(s)); return d.innerHTML; }

async function fetchJSON(url, opts) {
    const resp = await fetch(url, opts);
    if (!resp.ok) {
        const t = await resp.text();
        throw new Error('HTTP ' + resp.status + ': ' + t);
    }
    return resp.json();
}

// Load stats and state
async function loadState() {
    try {
        const data = await fetchJSON('api.php?action=stats');
        // Stats area
        const stats = [
            'Total de mensagens: ' + (data.total_messages || 0),
            'Mensagens últimas 24h: ' + (data.messages_last24h || 0),
            'Usuários ativos: ' + (data.active_users || 0),
            'Salas: ' + (data.rooms_count || 0)
        ].join('<br>');
        elq('#statsArea').innerHTML = stats;

        // Rooms
        const roomsArea = elq('#roomsArea');
        roomsArea.innerHTML = '';
        data.rooms.forEach(r => {
            const row = document.createElement('div');
            row.className = 'room-row';
            row.innerHTML = '<strong>' + escapeHtml(r.name) + '</strong> <span class="small">(' + (r.count || 0) + ' mensagens)</span> ' +
                '<button data-room="'+escapeHtml(r.name)+'" class="btn del-room">Deletar</button>';
            roomsArea.appendChild(row);
        });

        // Messages
        const messagesArea = elq('#messagesArea');
        messagesArea.innerHTML = '';
        data.recent_messages.forEach(m => {
            const div = document.createElement('div');
            div.className = 'msg-row';
            const roomLabel = m.room ? ('[' + escapeHtml(m.room) + '] ') : (m.recipient ? '[privado] ' : '');
            div.innerHTML = '<div class="msg-meta">' + roomLabel + '<strong>' + escapeHtml(m.user) + '</strong> • ' + escapeHtml(new Date(m.time).toLocaleString()) + '</div>' +
                '<div class="msg-text">' + escapeHtml(m.text) + '</div>' +
                '<div class="msg-actions"><button data-id="'+m.id+'" class="btn del-msg">Deletar</button></div>';
            messagesArea.appendChild(div);
        });

        // Settings
        elq('#siteTitle').value = data.site_title || '';
        elq('#maxMessages').value = data.max_messages || 500;
        elq('#cssEditor').value = data.css || '';

        // Wire up delete buttons
        elAll('.del-room').forEach(btn => btn.addEventListener('click', async (e) => {
            const name = btn.getAttribute('data-room');
            if (!confirm('Deletar sala "'+name+'"? Isso também removerá mensagens desta sala.')) return;
            try {
                await fetchJSON('api.php?action=delete_room', {
                    method: 'POST',
                    headers: {'Content-Type':'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({name})
                });
                loadState();
            } catch (err){ alert('Erro: ' + err.message); }
        }));

        elAll('.del-msg').forEach(btn => btn.addEventListener('click', async (e) => {
            const id = btn.getAttribute('data-id');
            if (!confirm('Deletar mensagem #' + id + '?')) return;
            try {
                await fetchJSON('api.php?action=delete_message', {
                    method: 'POST',
                    headers: {'Content-Type':'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({id})
                });
                loadState();
            } catch (err){ alert('Erro: ' + err.message); }
        }));

    } catch (err) {
        elq('#statsArea').textContent = 'Erro ao carregar: ' + err.message;
    }
}

// Create room
elq('#createRoomBtn').addEventListener('click', async () => {
    const name = elq('#newRoomName').value.trim();
    if (!name) return alert('Informe o nome da sala');
    try {
        await fetchJSON('api.php?action=create_room', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: new URLSearchParams({name})
        });
        elq('#newRoomName').value = '';
        loadState();
    } catch (err) { alert('Erro: ' + err.message); }
});

// Save settings
elq('#siteForm').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const siteTitle = elq('#siteTitle').value.trim();
    const maxMessages = elq('#maxMessages').value;
    try {
        await fetchJSON('api.php?action=edit_site', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: new URLSearchParams({site_title: siteTitle, max_messages: maxMessages})
        });
        elq('#adminMessage').textContent = 'Configurações salvas.';
        setTimeout(()=>elq('#adminMessage').textContent='',3000);
        loadState();
    } catch (err) { elq('#adminMessage').textContent = 'Erro: ' + err.message; }
});

// Save CSS
elq('#saveCssBtn').addEventListener('click', async () => {
    const css = elq('#cssEditor').value;
    try {
        await fetchJSON('api.php?action=edit_css', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: new URLSearchParams({css})
        });
        elq('#adminMessage').textContent = 'CSS salvo.';
        setTimeout(()=>elq('#adminMessage').textContent='',3000);
    } catch (err) { elq('#adminMessage').textContent = 'Erro: ' + err.message; }
});

// Initial load
loadState();
</script>
</body>
</html>