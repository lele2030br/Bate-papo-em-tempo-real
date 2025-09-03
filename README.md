```markdown
# Chat em tempo real (PHP + SQLite)

Versão: 1.0  
Gerado em: 2025-09-03  
Autor do deploy/contato: lele2030br

Descrição
---------
Este projeto é um chat em "quase tempo real" construído com PHP, SQLite e long-polling (AJAX). Ele fornece funcionalidades práticas para um chat leve que roda em hospedagens comuns sem dependências externas:

- Salas (rooms)
- Mensagens privadas (private messages)
- Indicador "digitando" (typing)
- Atualização imediata da mensagem ao enviar (servidor retorna o objeto da mensagem)
- Painel administrativo (criar/deletar salas, deletar mensagens, editar CSS e configurações, dashboard)

Arquivos principais
-------------------
- `index.php` — Interface pública do chat (login simples por nome, seleção de sala, lista de usuários, envio/recebimento de mensagens).
- `api.php` — API pública usada pelo frontend (ações: `get`, `send`, `create_room`, `typing`).
- `logout.php` — Encerra a sessão do usuário.
- `style.css` — Estilos do frontend (pode ser editado via painel admin).
- `chat.sqlite` — Banco de dados SQLite (é criado automaticamente pelo `api.php`).
- `admin/` — Painel administrativo:
  - `admin/login.php`, `admin/setup.php`, `admin/index.php`, `admin/api.php`, `admin/config.php`, `admin/logout.php`, `admin/style.css`, `admin/README_ADMIN.md`

Requisitos
----------
- PHP 7.1+ com PDO e driver pdo_sqlite habilitados (recomendado PHP 7.4+ ou 8.x).
- Servidor web (Apache, Nginx + PHP-FPM, etc).
- Permissão de escrita no diretório do projeto pelo processo do servidor web (para criação/edição de `chat.sqlite` e gravação de `style.css` via painel admin).
- Navegador moderno com suporte a Fetch API.

Instalação rápida
-----------------
1. Faça upload de todos os arquivos para o diretório público do servidor (ex.: `public_html` ou `/var/www/site`).

2. Ajuste permissões/ownership para que o processo do servidor web possa escrever no diretório:

   Exemplo (Ubuntu/Nginx com usuário `www-data`):
   ```bash
   sudo chown -R www-data:www-data /caminho/para/seu/diretorio
   sudo chmod -R 775 /caminho/para/seu/diretorio
   ```

   Se preferir, crie `chat.sqlite` manualmente e ajuste permissões:
   ```bash
   cd /caminho/para/seu/diretorio
   touch chat.sqlite
   chown www-data:www-data chat.sqlite
   chmod 664 chat.sqlite
   ```

3. Verifique se a extensão PDO SQLite está habilitada:
   ```bash
   php -m | grep -i sqlite
   ```
   Deve listar `pdo_sqlite` e/ou `sqlite3`.

4. Acesse `https://seu-dominio.tld/index.php`, insira um nome (login simples) e comece a usar.

Configuração do painel administrativo
-------------------------------------
- Se este for o primeiro acesso ao painel admin, abra `admin/setup.php` para criar o administrador inicial.
- Em seguida acesse `admin/login.php` e entre com as credenciais que você criou.
- O painel permite:
  - Ver estatísticas (total de mensagens, mensagens últimas 24h, usuários ativos, salas).
  - Criar e deletar salas (quando deletar sala, mensagens dessa sala são removidas).
  - Visualizar e deletar mensagens recentes (últimas 50).
  - Editar configurações do site (título, limite de histórico).
  - Editar `style.css` diretamente do painel.

Endpoints da API
----------------
Todos os endpoints usam sessão (o navegador envia cookie da sessão automaticamente).

1) GET api.php?action=get
- Query params:
  - `last_id` (int) — última mensagem conhecida pelo cliente.
  - `room` (string) — nome da sala (ex.: `Global`).
  - `recipient` (string) — nome do usuário para conversas privadas (se presente, `room` é ignorado).
- Resposta JSON:
```json
{
  "messages": [ { "id":123, "user":"Alice", "text":"Oi", "time":"2025-09-03T12:00:00Z" } ],
  "rooms": [ {"name":"Global"}, ... ],
  "users": [ {"name":"Bob", "current_room":"Global"}, ... ],
  "typing": [ {"user":"Eve","room":"Global","recipient":null}, ... ]
}
```

2) POST api.php?action=send
- Form-urlencoded:
  - `text` (string) — mensagem (obrigatório).
  - `room` (string) — sala de destino (omitir quando `recipient` for usada).
  - `recipient` (string) — nome do usuário para mensagem privada.
- Resposta de sucesso (o servidor retorna o objeto da mensagem inserida):
```json
{ "ok": true, "id": 456, "message": { "id":456, "user":"You","text":"Oi","time":"2025-09-03T12:01:00Z","room":"Global","recipient":null } }
```

3) POST api.php?action=create_room
- Form-urlencoded:
  - `name` (string) — nome da sala.
- Resposta:
```json
{ "ok": true }
```

4) POST api.php?action=typing
- Form-urlencoded:
  - `typing` = `'1'` ou `'0'`
  - `room` ou `recipient` — contexto do typing
- Resposta:
```json
{ "ok": true }
```

Admin API (admin/api.php)
- `action=stats` — retorna estatísticas e estado (usado pelo dashboard).
- `action=create_room` — cria sala (POST `name`).
- `action=delete_room` — deleta sala e mensagens (POST `name`).
- `action=delete_message` — deleta mensagem (POST `id`).
- `action=edit_site` — atualiza configurações (POST `site_title`, `max_messages`).
- `action=edit_css` — salva CSS enviado (POST `css`).

Exemplos (curl)
---------------
Enviar mensagem para sala Global:
```bash
curl -X POST -c cookies.txt -b cookies.txt \
  -d "text=Olá mundo&room=Global" "https://seu-dominio.tld/api.php?action=send"
```

Obter atualizações (long-polling):
```bash
curl -G -b cookies.txt --data-urlencode "last_id=0" --data-urlencode "room=Global" "https://seu-dominio.tld/api.php?action=get"
```

Criar sala (admin):
```bash
curl -X POST -b admin_cookies.txt -d "name=Suporte" "https://seu-dominio.tld/admin/api.php?action=create_room"
```

Comportamento e limites
-----------------------
- Mensagens têm limite de 1000 caracteres.
- Histórico mantido com limite padrão (configurável pelo painel admin). Implementação padrão: últimas 500 mensagens.
- Indicador de "digitando" expira automaticamente (ex.: 6 segundos sem atualização).
- Usuários são considerados ativos se houve atividade nos últimos ~40 segundos.

Recomendações de segurança (importante)
--------------------------------------
Esta aplicação é um exemplo funcional. Antes de usar em produção, aplique as seguintes melhorias:

1. HTTPS (TLS) — use certificados válidos (Let's Encrypt / Certbot).
2. Proteção do painel admin:
   - Use HTTPS obrigatório.
   - Considere proteger `/admin` com autenticação HTTP adicional, limitação por IP, ou VPN.
   - Adicione proteção CSRF (tokens nos formulários do painel e nas chamadas AJAX).
3. Autenticação de usuários:
   - Substitua o login simples por um sistema de contas com senhas e verificação por e-mail.
4. Validação e sanitização:
   - Embora o front-end escape saída, aplique camadas extras de sanitização e validação no backend.
5. Auditoria:
   - Ative logging/auditoria para ações administrativas (quem deletou mensagens/salas).
6. Rate limiting:
   - Evite abuso (flood de mensagens) adicionando limites por sessão/IP.
7. Backup:
   - Agende backups regulares do `chat.sqlite` (ou migre para um SGBD cliente/servidor para escala).


Deploy (exemplo Nginx + PHP-FPM)
--------------------------------
Exemplo básico de site Nginx (assumindo PHP-FPM socket em `/run/php/php8.1-fpm.sock`):

```
server {
    listen 80;
    server_name seu-dominio.tld;
    root /var/www/chat;
    index index.php index.html;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
    }

    location ~ /\. { deny all; }
}
```

Após configurar, reinicie Nginx e PHP-FPM:
```bash
sudo systemctl restart php8.1-fpm
sudo systemctl restart nginx
```

Backup & manutenção
-------------------
- Backup manual:
  ```bash
  cp /caminho/para/projeto/chat.sqlite /backup/chat.sqlite.$(date +%F_%T)
  ```
- Limpar (reset) banco em desenvolvimento:
  ```bash
  rm chat.sqlite
  ```
  No próximo acesso o banco será recriado automaticamente.

Migrações / Melhorias futuras sugeridas
--------------------------------------
- Mudar transporte para WebSockets (Node.js + Socket.IO ou Ratchet PHP) para reduzir latência e overhead do long-polling.
- Migrar de SQLite para MySQL/Postgres se espera muitos usuários concorrentes.
- Implementar roles (moderador, admin) e logs de auditoria.
- Notificações do navegador (Notification API) para novas mensagens quando a aba estiver em segundo plano.
- Upload de arquivos / imagens nas mensagens (com limitação e armazenamento controlado).
- Paginador e filtros avançados no painel admin (por usuário, intervalo, conteúdo).

Depuração / Troubleshooting
---------------------------
- Erro de permissão ao criar `chat.sqlite`:
  - Ajuste `chown`/`chmod` conforme mostrado na seção Instalação.
- PDOException: verifique se `pdo_sqlite` está habilitado no PHP e reinicie PHP-FPM.
- Mensagens não aparecem:
  - Abra o console do navegador (F12) e verifique erros de rede (requests a `api.php?action=get` e `send`).
  - Verifique se cookies de sessão estão habilitados (a API depende de sessão para identificar usuário).
- Erro ao salvar CSS no painel admin:
  - Verifique permissões de escrita no arquivo `style.css` e no diretório.

Boas práticas de operação
-------------------------
- Execute o site por trás de HTTPS.
- Monitore uso de disco (SQLite cresce com mensagens).
- Faça backups diários ou com a frequência adequada ao seu uso.
- Em caso de alto tráfego, migre para um SGBD dedicado e/ou WebSockets.

Licença
-------
Use este código como base — não há licença formal anexada neste repositório por padrão. Se pretende usar em produção, escolha e adicione uma licença (ex.: MIT, Apache-2.0) ao projeto.

Créditos
--------
Desenvolvido com foco em simplicidade para hospedagens compartilhadas (PHP + SQLite). Interface e lógica foram projetadas para fácil entendimento e extensibilidade.

Contato
-------
Para suporte ou customizações adicionais (ex.: WebSockets, autenticação com contas, migração para MySQL/Postgres, integração com autenticação externa), entre em contato com: lele2030br

---
```