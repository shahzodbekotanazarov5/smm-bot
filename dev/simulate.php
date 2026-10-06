<?php

declare(strict_types=1);

/**
 * LOCAL DEV-ONLY chat simulator. Lets you test the bot in a browser without
 * a real Telegram account, ngrok, or a public webhook — it feeds synthetic
 * Update arrays through the EXACT SAME App\Core\UpdatePipeline the real
 * webhook uses, so it's testing the real bot logic, just with a fake
 * transport instead of Telegram's API.
 *
 * SAFETY: this file refuses to run unless PHP's built-in dev server is
 * serving it (PHP_SAPI === 'cli-server') — i.e. only when launched via
 * `php -S 127.0.0.1:8000 dev/simulate.php`. On a real Apache/PHP-FPM
 * deployment (shared hosting) this guard always fails closed. It also lives
 * outside public_html, so a normal deployment never exposes it at all.
 * NEVER upload the dev/ folder to production hosting.
 */

if (PHP_SAPI !== 'cli-server') {
    http_response_code(403);
    exit('This simulator only runs under `php -S ... dev/simulate.php` (PHP built-in server). Refusing to run under ' . PHP_SAPI . '.');
}

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Core\Config;
use App\Core\TelegramApi;
use App\Core\Update;
use App\Core\UpdatePipeline;

header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON body']);
        exit;
    }

    $telegramId = (int) ($body['telegram_id'] ?? 0);
    $username = (string) ($body['username'] ?? '');
    $firstName = (string) ($body['first_name'] ?? 'Test');
    $languageCode = (string) ($body['language_code'] ?? 'uz');
    $action = (string) ($body['action'] ?? 'message');
    $updateId = (int) round(microtime(true) * 1000);

    if ($telegramId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'telegram_id is required']);
        exit;
    }

    $from = [
        'id' => $telegramId,
        'username' => $username !== '' ? $username : null,
        'first_name' => $firstName,
        'language_code' => $languageCode,
    ];

    if ($action === 'callback') {
        $updateArray = [
            'update_id' => $updateId,
            'callback_query' => [
                'id' => 'sim' . $updateId,
                'from' => $from,
                'data' => (string) ($body['callback_data'] ?? ''),
                'message' => [
                    'message_id' => (int) ($body['callback_message_id'] ?? 0),
                    'chat' => ['id' => $telegramId, 'type' => 'private'],
                ],
            ],
        ];
    } else {
        $updateArray = [
            'update_id' => $updateId,
            'message' => [
                'message_id' => $updateId,
                'from' => $from,
                'chat' => ['id' => $telegramId, 'type' => 'private'],
                'text' => (string) ($body['text'] ?? ''),
            ],
        ];
    }

    TelegramApi::enableSimulation();

    try {
        UpdatePipeline::handle(Update::fromArray($updateArray));
    } catch (\Throwable $e) {
        echo json_encode(['error' => $e->getMessage(), 'outbox' => TelegramApi::drainOutbox()]);
        exit;
    }

    echo json_encode(['outbox' => TelegramApi::drainOutbox()], JSON_UNESCAPED_UNICODE);
    exit;
}

$adminIds = (array) Config::get('admin_bootstrap_ids', []);
$firstAdminId = $adminIds[0] ?? 0;
$botToken = (string) Config::get('bot.token', '');
$botConfigured = $botToken !== '' && !str_contains($botToken, 'PUT_YOUR');

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="uz">
<head>
<meta charset="utf-8">
<title>SMM Bot — Local Simulator</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root {
    --bg: #0e1621; --panel: #17212b; --bubble-bot: #182533; --bubble-user: #2b5278;
    --text: #e7ecf0; --muted: #8b98a5; --accent: #4ea4f5; --border: #23303f;
  }
  * { box-sizing: border-box; }
  body { margin: 0; background: var(--bg); color: var(--text); font-family: -apple-system, Segoe UI, Roboto, sans-serif; height: 100vh; display: flex; flex-direction: column; }
  header { background: var(--panel); border-bottom: 1px solid var(--border); padding: 10px 16px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
  header h1 { font-size: 15px; margin: 0 12px 0 0; white-space: nowrap; }
  header input { background: var(--bg); border: 1px solid var(--border); color: var(--text); border-radius: 6px; padding: 6px 8px; font-size: 13px; width: 130px; }
  header button.small { background: var(--border); color: var(--text); border: none; border-radius: 6px; padding: 6px 10px; font-size: 12px; cursor: pointer; }
  header button.small:hover { background: var(--accent); }
  .warn { background: #3a2a1a; color: #f0b429; padding: 6px 16px; font-size: 12px; }
  #chat { flex: 1; overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 10px; }
  .row { display: flex; }
  .row.user { justify-content: flex-end; }
  .bubble { max-width: 78%; padding: 8px 12px; border-radius: 10px; font-size: 14px; line-height: 1.4; white-space: pre-wrap; word-wrap: break-word; }
  .bubble.bot { background: var(--bubble-bot); border-top-left-radius: 2px; }
  .bubble.user { background: var(--bubble-user); border-top-right-radius: 2px; }
  .kb { display: flex; flex-direction: column; gap: 4px; margin-top: 6px; }
  .kb-row { display: flex; gap: 4px; }
  .kb button { flex: 1; background: #223140; color: var(--accent); border: 1px solid var(--border); border-radius: 6px; padding: 6px 8px; font-size: 13px; cursor: pointer; }
  .kb button:hover { background: var(--accent); color: #fff; }
  #replyKb { display: flex; flex-wrap: wrap; gap: 6px; padding: 8px 16px 0; }
  #replyKb button { background: var(--panel); color: var(--text); border: 1px solid var(--border); border-radius: 16px; padding: 6px 12px; font-size: 13px; cursor: pointer; }
  #replyKb button:hover { border-color: var(--accent); color: var(--accent); }
  footer { background: var(--panel); border-top: 1px solid var(--border); padding: 10px 16px; display: flex; gap: 8px; }
  footer input { flex: 1; background: var(--bg); border: 1px solid var(--border); color: var(--text); border-radius: 8px; padding: 10px 12px; font-size: 14px; }
  footer button { background: var(--accent); color: #fff; border: none; border-radius: 8px; padding: 0 18px; font-size: 14px; cursor: pointer; }
  .toast { position: fixed; bottom: 90px; left: 50%; transform: translateX(-50%); background: #223140; color: var(--text); padding: 10px 16px; border-radius: 8px; font-size: 13px; box-shadow: 0 4px 12px rgba(0,0,0,.4); opacity: 0; transition: opacity .2s; pointer-events: none; }
  .toast.show { opacity: 1; }
  .meta { font-size: 11px; color: var(--muted); margin-top: 10px; text-align: center; }
</style>
</head>
<body>
<header>
  <h1>🧪 Bot Simulator</h1>
  <input id="telegramId" type="text" placeholder="Telegram ID">
  <input id="firstName" type="text" placeholder="Ism" value="Test">
  <button class="small" id="btnNewUser">👤 Yangi foydalanuvchi</button>
  <button class="small" id="btnAdmin">🛠 Admin sifatida</button>
  <button class="small" id="btnClear">🔄 Tozalash</button>
</header>
<?php if (!$botConfigured): ?>
<div class="warn">⚠️ config/config.php da bot.token hali to'ldirilmagan — bu simulator uchun muammo emas (Telegramga chiqmaydi), lekin haqiqiy botni ishga tushirishdan oldin to'ldiring.</div>
<?php endif; ?>
<div id="chat"></div>
<div id="replyKb"></div>
<footer>
  <input id="textInput" type="text" placeholder="Xabar yozing..." autocomplete="off">
  <button id="btnSend">Yuborish</button>
</footer>
<div class="toast" id="toast"></div>
<div class="meta">Faqat lokal test uchun. Bu sahifa hech qachon haqiqiy hostingga yuklanmasligi kerak (dev/ papkasi).</div>

<script>
const ADMIN_ID = <?= json_encode((int) $firstAdminId) ?>;
let telegramId = null;

function randomId() {
  return Math.floor(1000000000 + Math.random() * 8999999999);
}

function newUser(id) {
  telegramId = id || randomId();
  document.getElementById('telegramId').value = telegramId;
  document.getElementById('chat').innerHTML = '';
  document.getElementById('replyKb').innerHTML = '';
}

document.getElementById('btnNewUser').onclick = () => newUser();
document.getElementById('btnAdmin').onclick = () => {
  if (!ADMIN_ID) { showToast('config.php da admin_bootstrap_ids bo\'sh — avval o\'zingizning Telegram ID\'ingizni qo\'shing.'); return; }
  newUser(ADMIN_ID);
};
document.getElementById('btnClear').onclick = () => { document.getElementById('chat').innerHTML = ''; };
document.getElementById('telegramId').onchange = (e) => {
  const v = parseInt(e.target.value, 10);
  if (v > 0) { telegramId = v; }
};

function showToast(text) {
  const t = document.getElementById('toast');
  t.textContent = text;
  t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), 2500);
}

function bubble(from, text, replyMarkup, messageId) {
  const row = document.createElement('div');
  row.className = 'row ' + from;
  row.dataset.messageId = messageId || '';

  const b = document.createElement('div');
  b.className = 'bubble ' + from;
  b.textContent = text || '';
  b.innerHTML = (text || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/&lt;b&gt;/g, '<b>').replace(/&lt;\/b&gt;/g, '</b>')
    .replace(/&lt;code&gt;/g, '<code>').replace(/&lt;\/code&gt;/g, '</code>')
    .replace(/&lt;i&gt;/g, '<i>').replace(/&lt;\/i&gt;/g, '</i>')
    .replace(/\n/g, '<br>');
  row.appendChild(b);

  if (replyMarkup && replyMarkup.inline_keyboard) {
    const kb = document.createElement('div');
    kb.className = 'kb';
    replyMarkup.inline_keyboard.forEach(r => {
      const kbRow = document.createElement('div');
      kbRow.className = 'kb-row';
      r.forEach(btn => {
        const el = document.createElement('button');
        el.textContent = btn.text;
        el.onclick = () => {
          if (btn.url) { window.open(btn.url, '_blank'); return; }
          if (btn.callback_data) { send('callback', null, btn.callback_data, messageId); }
        };
        kbRow.appendChild(el);
      });
      kb.appendChild(kbRow);
    });
    b.appendChild(kb);
  }

  return row;
}

function renderReplyKeyboard(replyMarkup) {
  const el = document.getElementById('replyKb');
  if (!replyMarkup) return;
  if (replyMarkup.remove_keyboard) { el.innerHTML = ''; return; }
  if (!replyMarkup.keyboard) return;
  el.innerHTML = '';
  replyMarkup.keyboard.forEach(row => {
    row.forEach(btn => {
      const b = document.createElement('button');
      b.textContent = typeof btn === 'string' ? btn : btn.text;
      b.onclick = () => send('message', b.textContent);
      el.appendChild(b);
    });
  });
}

function applyOutbox(outbox) {
  const chat = document.getElementById('chat');
  outbox.forEach(item => {
    if (item.method === 'answerCallbackQuery') {
      if (item.text) showToast(item.text);
      return;
    }
    if (item.method === 'deleteMessage') {
      const existing = chat.querySelector('[data-message-id="' + item.message_id + '"]');
      if (existing) existing.remove();
      return;
    }
    if (item.method === 'editMessageText') {
      const existing = chat.querySelector('[data-message-id="' + item.message_id + '"]');
      const fresh = bubble('bot', item.text, item.reply_markup, item.message_id);
      if (existing) { existing.replaceWith(fresh); } else { chat.appendChild(fresh); }
      renderReplyKeyboard(item.reply_markup);
      return;
    }
    // sendMessage / sendPhoto
    chat.appendChild(bubble('bot', item.text, item.reply_markup, item.message_id));
    renderReplyKeyboard(item.reply_markup);
  });
  chat.scrollTop = chat.scrollHeight;
}

async function send(action, text, callbackData, callbackMessageId) {
  if (!telegramId) { newUser(); }

  if (action === 'message') {
    document.getElementById('chat').appendChild(bubble('user', text, null, null));
    document.getElementById('chat').scrollTop = document.getElementById('chat').scrollHeight;
  }

  const res = await fetch(location.pathname, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      telegram_id: telegramId,
      username: 'sim_' + telegramId,
      first_name: document.getElementById('firstName').value || 'Test',
      language_code: 'uz',
      action: action,
      text: text,
      callback_data: callbackData,
      callback_message_id: callbackMessageId,
    }),
  });

  const data = await res.json();
  if (data.error) { showToast('Xatolik: ' + data.error); }
  applyOutbox(data.outbox || []);
}

document.getElementById('btnSend').onclick = () => {
  const input = document.getElementById('textInput');
  const text = input.value.trim();
  if (!text) return;
  input.value = '';
  send('message', text);
};
document.getElementById('textInput').addEventListener('keydown', (e) => {
  if (e.key === 'Enter') { document.getElementById('btnSend').click(); }
});

newUser();
send('message', '/start');
</script>
</body>
</html>
