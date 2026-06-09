<?php

declare(strict_types=1);

namespace Iae\Central\Controllers;

use Iae\Central\Services\RabbitMqService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class BoardController
{
    public function __construct(
        private readonly RabbitMqService $rabbitMq,
        private readonly string $exchange,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $board = $this->rabbitMq->peekBoard(30);
        $response->getBody()->write($this->renderHtml($board));

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function json(Request $request, Response $response): Response
    {
        $board = $this->rabbitMq->peekBoard(30);

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
        foreach ($board['messages'] as $entry) {
            $routingKey = htmlspecialchars((string) ($entry['routing_key'] ?? '—'), ENT_QUOTES, 'UTF-8');
            $payload = $entry['payload'] ?? [];
            $subject = htmlspecialchars($this->formatSubject($payload), ENT_QUOTES, 'UTF-8');
            $publishedAt = htmlspecialchars((string) ($payload['published_at'] ?? '—'), ENT_QUOTES, 'UTF-8');
            $messageBody = htmlspecialchars(
                json_encode($payload['message'] ?? $payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                ENT_QUOTES,
                'UTF-8',
            );

            $cards .= <<<HTML
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

    <div class="grid">{$cards}</div>

    <footer>
      <a href="/">← Beranda mock server</a>
      <a href="/api/v1/messages/board">JSON API</a>
      <a href="/health">Health check</a>
    </footer>
  </div>
</body>
</html>
HTML;
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
