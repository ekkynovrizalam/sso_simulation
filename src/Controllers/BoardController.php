<?php

declare(strict_types=1);

namespace Iae\Central\Controllers;

use Iae\Central\Services\RabbitMqService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class BoardController
{
    private const DISPLAY_LIMIT = 30;

    public function __construct(
        private readonly RabbitMqService $rabbitMq,
        private readonly string $exchange,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $board = $this->rabbitMq->peekBoard(self::DISPLAY_LIMIT);
        $response->getBody()->write($this->renderHtml($board));

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function search(Request $request, Response $response): Response
    {
        $query = trim((string) ($request->getQueryParams()['q'] ?? ''));
        $board = $this->rabbitMq->peekBoard(0);

        if (!$board['ok']) {
            $payload = [
                'status' => 'error',
                'error' => $board['error'],
                'queue_total' => 0,
                'match_count' => 0,
                'messages' => [],
            ];

            $response->getBody()->write((string) json_encode($payload, JSON_THROW_ON_ERROR));

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(503);
        }

        $needle = mb_strtolower($query, 'UTF-8');
        $matches = $needle === ''
            ? $board['messages']
            : array_values(array_filter(
                $board['messages'],
                fn (array $entry): bool => $this->entryMatchesQuery($entry, $needle),
            ));

        $payload = [
            'status' => 'ok',
            'query' => $query,
            'queue_total' => $board['message_count'],
            'match_count' => count($matches),
            'messages' => $matches,
        ];

        $response->getBody()->write((string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json');
    }

    public function json(Request $request, Response $response): Response
    {
        $board = $this->rabbitMq->peekBoard(self::DISPLAY_LIMIT);

        $payload = [
            'status' => $board['ok'] ? 'ok' : 'error',
            'broker_connected' => $board['ok'],
            'exchange' => $this->exchange,
            'queue' => $board['queue'],
            'message_count' => $board['message_count'],
            'messages' => $board['messages'],
        ];

        if (!$board['ok']) {
            $payload['error'] = $board['error'];
        }

        $response->getBody()->write((string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($board['ok'] ? 200 : 503);
    }

    /** @param array{ok: bool, error: ?string, queue: string, message_count: int, messages: list<array<string, mixed>>} $board */
    private function renderHtml(array $board): string
    {
        $exchange = htmlspecialchars($this->exchange, ENT_QUOTES, 'UTF-8');
        $queue = htmlspecialchars($board['queue'], ENT_QUOTES, 'UTF-8');
        $messageCount = (int) $board['message_count'];

        if (!$board['ok']) {
            $statusClass = 'down';
            $statusLabel = 'Tidak terkoneksi';
            $statusHint = 'RabbitMQ belum siap. Jalankan <code>docker compose up -d</code> lalu refresh halaman ini.';
            $error = htmlspecialchars((string) $board['error'], ENT_QUOTES, 'UTF-8');
            $statusHint .= '<br><span class="err">' . $error . '</span>';
        } elseif ($messageCount === 0) {
            $statusClass = 'waiting';
            $statusLabel = 'Terhubung — belum ada pesan';
            $statusHint = 'Broker aktif, tetapi queue masih kosong. Publish pesan Anda — jika berhasil, akan muncul di bawah.';
        } else {
            $statusClass = 'live';
            $statusLabel = 'Terhubung — ' . $messageCount . ' pesan di papan';
            $statusHint = 'Pesan Anda sudah sampai di RabbitMQ. Semua publish ke exchange <code>' . $exchange . '</code> akan tampil di sini (routing key bebas).';
        }

        $cards = '';
        $cardCount = count($board['messages']);
        foreach ($board['messages'] as $entry) {
            $cards .= $this->renderCard($entry);
        }

        if ($cards === '') {
            $cards = <<<HTML
            <div class="empty">
              <p>Belum ada pengumuman.</p>
              <p class="hint">Coba publish lewat <code>POST /api/v1/messages/publish</code> atau langsung dari Laravel ke exchange <code>{$exchange}</code> — routing key boleh apa saja.</p>
            </div>
            HTML;
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="refresh" content="5">
  <title>Papan Pengumuman RabbitMQ — IAE Lab</title>
  <style>
    :root {
      --bg: #0d1117;
      --surface: #161b22;
      --border: #30363d;
      --text: #e6edf3;
      --muted: #8b949e;
      --accent: #58a6ff;
      --green: #3fb950;
      --amber: #d29922;
      --red: #f85149;
    }
    * { box-sizing: border-box; }
    body {
      font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
      margin: 0;
      min-height: 100vh;
      background: var(--bg);
      color: var(--text);
      line-height: 1.55;
    }
    .wrap { max-width: 900px; margin: 0 auto; padding: 2rem 1.25rem 3rem; }
    .top { display: flex; flex-wrap: wrap; gap: 1rem; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem; }
    h1 { margin: 0 0 0.35rem; font-size: 1.65rem; }
    .lead { margin: 0; color: var(--muted); max-width: 36rem; }
    .status {
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 0.85rem 1rem;
      min-width: 240px;
      background: var(--surface);
    }
    .status.live { border-color: rgba(63, 185, 80, 0.45); }
    .status.waiting { border-color: rgba(210, 153, 34, 0.45); }
    .status.down { border-color: rgba(248, 81, 73, 0.45); }
    .pill {
      display: inline-block;
      font-size: 0.72rem;
      font-weight: 700;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      padding: 0.2rem 0.55rem;
      border-radius: 999px;
      margin-bottom: 0.45rem;
    }
    .live .pill { background: rgba(63, 185, 80, 0.18); color: var(--green); }
    .waiting .pill { background: rgba(210, 153, 34, 0.18); color: var(--amber); }
    .down .pill { background: rgba(248, 81, 73, 0.18); color: var(--red); }
    .status p { margin: 0; font-size: 0.88rem; color: var(--muted); }
    .err { color: var(--red); }
    .meta {
      display: flex;
      flex-wrap: wrap;
      gap: 0.5rem 1rem;
      font-size: 0.82rem;
      color: var(--muted);
      margin: 1rem 0 1.5rem;
    }
    code {
      font-family: ui-monospace, "SF Mono", Menlo, monospace;
      font-size: 0.85em;
      background: rgba(88, 166, 255, 0.12);
      color: var(--accent);
      padding: 0.1rem 0.35rem;
      border-radius: 4px;
    }
    .grid { display: grid; gap: 1rem; }
    .card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 1rem 1.1rem;
    }
    .card header {
      display: flex;
      justify-content: space-between;
      gap: 0.75rem;
      align-items: center;
      margin-bottom: 0.55rem;
    }
    .rk {
      font-family: ui-monospace, "SF Mono", Menlo, monospace;
      font-size: 0.82rem;
      color: var(--green);
      background: rgba(63, 185, 80, 0.12);
      padding: 0.15rem 0.45rem;
      border-radius: 4px;
    }
    time { font-size: 0.78rem; color: var(--muted); }
    .from { margin: 0 0 0.65rem; font-size: 0.9rem; color: var(--muted); }
    pre {
      margin: 0;
      padding: 0.75rem;
      background: #0d1117;
      border: 1px solid var(--border);
      border-radius: 8px;
      overflow-x: auto;
      font-size: 0.78rem;
      line-height: 1.45;
      white-space: pre-wrap;
      word-break: break-word;
    }
    .empty {
      text-align: center;
      padding: 2.5rem 1rem;
      border: 1px dashed var(--border);
      border-radius: 10px;
      color: var(--muted);
    }
    .empty p { margin: 0 0 0.5rem; }
    .hint { font-size: 0.88rem; }
    footer {
      margin-top: 2rem;
      font-size: 0.8rem;
      color: var(--muted);
      display: flex;
      flex-wrap: wrap;
      gap: 0.75rem;
    }
    footer a { color: var(--accent); text-decoration: none; }
    footer a:hover { text-decoration: underline; }
    .search-bar {
      margin-bottom: 1.25rem;
      padding: 1rem 1.1rem;
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 10px;
    }
    .search-bar label {
      display: block;
      font-size: 0.88rem;
      font-weight: 600;
      margin-bottom: 0.45rem;
    }
    .search-row {
      display: flex;
      gap: 0.5rem;
      align-items: center;
    }
    .search-row input {
      flex: 1;
      padding: 0.55rem 0.75rem;
      font-size: 0.9rem;
      font-family: inherit;
      color: var(--text);
      background: var(--bg);
      border: 1px solid var(--border);
      border-radius: 8px;
      outline: none;
    }
    .search-row input:focus {
      border-color: var(--accent);
      box-shadow: 0 0 0 2px rgba(88, 166, 255, 0.2);
    }
    .search-row input::placeholder { color: var(--muted); }
    .search-row button {
      padding: 0.55rem 0.85rem;
      font-size: 0.85rem;
      font-family: inherit;
      color: var(--muted);
      background: transparent;
      border: 1px solid var(--border);
      border-radius: 8px;
      cursor: pointer;
    }
    .search-row button:hover {
      color: var(--text);
      border-color: var(--muted);
    }
    .search-hint {
      margin: 0.45rem 0 0;
      font-size: 0.8rem;
      color: var(--muted);
    }
    #search-status {
      margin: 0.35rem 0 0;
      font-size: 0.82rem;
      color: var(--accent);
      min-height: 1.2em;
    }
    .card.is-hidden { display: none; }
    .no-search-results {
      display: none;
      text-align: center;
      padding: 2rem 1rem;
      border: 1px dashed var(--border);
      border-radius: 10px;
      color: var(--muted);
    }
    .no-search-results.is-visible { display: block; }
    .no-search-results p { margin: 0; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="top">
      <div>
        <h1>Papan Pengumuman RabbitMQ</h1>
        <p class="lead">
          Buka halaman ini setelah publish. <strong>Kalau pesan Anda muncul di bawah = berhasil terkoneksi ke broker.</strong>
        </p>
      </div>
      <div class="status {$statusClass}">
        <span class="pill">{$statusLabel}</span>
        <p>{$statusHint}</p>
      </div>
    </div>

    <div class="meta">
      <span>Exchange: <code>{$exchange}</code></span>
      <span>Queue: <code>{$queue}</code></span>
      <span>Binding: <code>#</code> (semua routing key)</span>
      <span>Auto-refresh: 5 detik</span>
    </div>

    <div class="search-bar">
      <label for="board-search">Cari di body JSON, routing key, atau pengirim</label>
      <div class="search-row">
        <input
          type="search"
          id="board-search"
          placeholder="Contoh: nim, team-a, hello world, routing.key…"
          autocomplete="off"
          spellcheck="false"
        >
        <button type="button" id="board-search-clear" title="Hapus pencarian">Hapus</button>
      </div>
      <p class="search-hint">Pencarian tidak peka huruf besar/kecil. Mencari di seluruh <strong>{$messageCount}</strong> pesan di queue (bukan hanya yang tampil di bawah).</p>
      <p id="search-status" aria-live="polite"></p>
    </div>

    <div class="grid" id="board-grid" data-displayed="{$cardCount}" data-queue-total="{$messageCount}">{$cards}</div>
    <div class="no-search-results" id="no-search-results">
      <p>Tidak ada pesan yang cocok dengan pencarian Anda.</p>
    </div>

    <footer>
      <a href="/">← Beranda mock server</a>
      <a href="/api/v1/messages/board">JSON API</a>
      <a href="/health">Health check</a>
    </footer>
  </div>
  <script>
    (function () {
      const STORAGE_KEY = 'iae-board-search';
      const SEARCH_URL = '/api/v1/messages/board/search';
      const input = document.getElementById('board-search');
      const clearBtn = document.getElementById('board-search-clear');
      const status = document.getElementById('search-status');
      const grid = document.getElementById('board-grid');
      const noResults = document.getElementById('no-search-results');
      if (!input || !grid) return;

      const displayed = parseInt(grid.dataset.displayed || '0', 10);
      const queueTotal = parseInt(grid.dataset.queueTotal || '0', 10);
      const defaultGridHtml = grid.innerHTML;
      let debounceTimer = null;
      let searchRequestId = 0;

      function escapeHtml(value) {
        return String(value)
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;');
      }

      function formatSubject(payload) {
        if (payload.team) return payload.team;
        if (payload.subject) return payload.subject;
        if (payload.api_key) return payload.api_key;
        return 'Unknown publisher';
      }

      function renderCard(entry) {
        const payload = entry.payload || {};
        const routingKey = entry.routing_key || '—';
        const subject = formatSubject(payload);
        const publishedAt = payload.published_at || '—';
        const messageBody = JSON.stringify(payload.message || payload, null, 2);

        return (
          '<article class="card">' +
            '<header>' +
              '<span class="rk">' + escapeHtml(routingKey) + '</span>' +
              '<time>' + escapeHtml(publishedAt) + '</time>' +
            '</header>' +
            '<p class="from">Dari: <strong>' + escapeHtml(subject) + '</strong></p>' +
            '<pre>' + escapeHtml(messageBody) + '</pre>' +
          '</article>'
        );
      }

      function setDefaultStatus() {
        if (!status) return;
        if (queueTotal === 0) {
          status.textContent = '';
          return;
        }
        if (queueTotal <= displayed) {
          status.textContent = 'Menampilkan semua ' + queueTotal + ' pesan di queue.';
          return;
        }
        status.textContent = 'Menampilkan ' + displayed + ' pesan terbaru dari ' + queueTotal + ' pesan di queue.';
      }

      function restoreDefaultView() {
        grid.innerHTML = defaultGridHtml;
        if (noResults) noResults.classList.remove('is-visible');
        setDefaultStatus();
      }

      function runSearch(query) {
        const requestId = ++searchRequestId;
        if (status) status.textContent = 'Mencari di seluruh ' + queueTotal + ' pesan…';

        fetch(SEARCH_URL + '?q=' + encodeURIComponent(query))
          .then(function (response) { return response.json(); })
          .then(function (data) {
            if (requestId !== searchRequestId) return;

            if (data.status !== 'ok') {
              if (status) status.textContent = 'Pencarian gagal: ' + (data.error || 'broker tidak tersedia');
              return;
            }

            const matches = data.messages || [];
            grid.innerHTML = matches.map(renderCard).join('');

            if (noResults) {
              noResults.classList.toggle('is-visible', matches.length === 0 && queueTotal > 0);
            }
            if (status) {
              status.textContent = matches.length + ' dari ' + data.queue_total + ' pesan cocok dengan "' + query + '".';
            }
          })
          .catch(function () {
            if (requestId !== searchRequestId) return;
            if (status) status.textContent = 'Pencarian gagal. Coba lagi.';
          });
      }

      function handleInput() {
        const query = input.value.trim();
        sessionStorage.setItem(STORAGE_KEY, input.value);
        clearTimeout(debounceTimer);

        debounceTimer = setTimeout(function () {
          if (query === '') {
            restoreDefaultView();
            return;
          }
          runSearch(query);
        }, 300);
      }

      const saved = sessionStorage.getItem(STORAGE_KEY);
      if (saved) {
        input.value = saved;
        if (saved.trim() !== '') {
          runSearch(saved.trim());
        } else {
          setDefaultStatus();
        }
      } else {
        setDefaultStatus();
      }

      input.addEventListener('input', handleInput);
      clearBtn.addEventListener('click', function () {
        input.value = '';
        sessionStorage.removeItem(STORAGE_KEY);
        searchRequestId += 1;
        clearTimeout(debounceTimer);
        restoreDefaultView();
        input.focus();
      });
    })();
  </script>
</body>
</html>
HTML;
    }

    /** @param array<string, mixed> $entry */
    private function renderCard(array $entry): string
    {
        $routingKey = htmlspecialchars((string) ($entry['routing_key'] ?? '—'), ENT_QUOTES, 'UTF-8');
        $payload = $entry['payload'] ?? [];
        $subject = htmlspecialchars($this->formatSubject($payload), ENT_QUOTES, 'UTF-8');
        $publishedAt = htmlspecialchars((string) ($payload['published_at'] ?? '—'), ENT_QUOTES, 'UTF-8');
        $messageBody = htmlspecialchars(
            json_encode($payload['message'] ?? $payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            ENT_QUOTES,
            'UTF-8',
        );

        return <<<HTML
            <article class="card">
              <header>
                <span class="rk">{$routingKey}</span>
                <time>{$publishedAt}</time>
              </header>
              <p class="from">Dari: <strong>{$subject}</strong></p>
              <pre>{$messageBody}</pre>
            </article>
            HTML;
    }

    /** @param array<string, mixed> $entry */
    private function entryMatchesQuery(array $entry, string $needle): bool
    {
        return str_contains($this->buildSearchableText($entry), $needle);
    }

    /** @param array<string, mixed> $entry */
    private function buildSearchableText(array $entry): string
    {
        $routingKey = (string) ($entry['routing_key'] ?? '');
        $payload = $entry['payload'] ?? [];
        $subject = $this->formatSubject($payload);
        $publishedAt = (string) ($payload['published_at'] ?? '');
        $jsonRaw = json_encode($payload['message'] ?? $payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return mb_strtolower($routingKey . ' ' . $subject . ' ' . $publishedAt . ' ' . $jsonRaw, 'UTF-8');
    }

    /** @param array<string, mixed> $payload */
    private function formatSubject(array $payload): string
    {
        if (isset($payload['team']) && is_string($payload['team']) && $payload['team'] !== '') {
            return $payload['team'];
        }

        if (isset($payload['subject']) && is_string($payload['subject']) && $payload['subject'] !== '') {
            return $payload['subject'];
        }

        if (isset($payload['api_key']) && is_string($payload['api_key']) && $payload['api_key'] !== '') {
            return $payload['api_key'];
        }

        return 'Unknown publisher';
    }
}
