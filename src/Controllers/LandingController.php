<?php

declare(strict_types=1);

namespace Iae\Central\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class LandingController
{
    public function index(Request $request, Response $response): Response
    {
        $response->getBody()->write($this->render());

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    private function render(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>IAE Central Mock Server</title>
  <style>
    :root {
      --bg: #0d1117;
      --surface: #161b22;
      --border: #30363d;
      --text: #e6edf3;
      --muted: #8b949e;
      --accent: #58a6ff;
      --accent-soft: #1f3a5f;
      --green: #3fb950;
      --amber: #d29922;
      --purple: #a371f7;
    }
    * { box-sizing: border-box; }
    body {
      font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
      margin: 0;
      min-height: 100vh;
      background: var(--bg);
      color: var(--text);
      line-height: 1.6;
    }
    .wrap { max-width: 960px; margin: 0 auto; padding: 2.5rem 1.5rem 3rem; }
    header { margin-bottom: 2.5rem; }
    .badge {
      display: inline-block;
      font-size: 0.75rem;
      font-weight: 600;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      color: var(--amber);
      background: rgba(210, 153, 34, 0.15);
      border: 1px solid rgba(210, 153, 34, 0.35);
      padding: 0.25rem 0.6rem;
      border-radius: 999px;
      margin-bottom: 1rem;
    }
    h1 {
      font-size: clamp(1.75rem, 4vw, 2.25rem);
      font-weight: 700;
      margin: 0 0 0.75rem;
      letter-spacing: -0.02em;
    }
    .lead { color: var(--muted); font-size: 1.05rem; max-width: 42rem; margin: 0; }
    .cards {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
      gap: 1rem;
      margin: 2rem 0 2.5rem;
    }
    .card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 1.25rem 1.35rem;
    }
    .card h2 {
      font-size: 0.95rem;
      margin: 0 0 0.5rem;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }
    .card p { margin: 0; font-size: 0.875rem; color: var(--muted); }
    .dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
    .dot.sso { background: var(--accent); }
    .dot.soap { background: var(--amber); }
    .dot.mq { background: var(--green); }
    section h2 {
      font-size: 1.1rem;
      margin: 0 0 1rem;
      padding-bottom: 0.5rem;
      border-bottom: 1px solid var(--border);
    }
    table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.875rem;
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 10px;
      overflow: hidden;
    }
    th, td { padding: 0.65rem 0.9rem; text-align: left; border-bottom: 1px solid var(--border); }
    th { background: #21262d; color: var(--muted); font-weight: 600; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.03em; }
    tr:last-child td { border-bottom: none; }
    code {
      font-family: ui-monospace, "SF Mono", Menlo, monospace;
      font-size: 0.8em;
      background: var(--accent-soft);
      color: var(--accent);
      padding: 0.15rem 0.4rem;
      border-radius: 4px;
    }
    .method {
      font-weight: 700;
      font-size: 0.75rem;
      padding: 0.15rem 0.45rem;
      border-radius: 4px;
    }
    .get { background: rgba(63, 185, 80, 0.2); color: var(--green); }
    .post { background: rgba(88, 166, 255, 0.2); color: var(--accent); }
    .links { margin-top: 2rem; display: flex; flex-wrap: wrap; gap: 0.75rem; }
    .links a {
      color: var(--accent);
      text-decoration: none;
      font-size: 0.9rem;
      padding: 0.5rem 1rem;
      border: 1px solid var(--border);
      border-radius: 8px;
      background: var(--surface);
      transition: border-color 0.15s, background 0.15s;
    }
    .links a:hover { border-color: var(--accent); background: var(--accent-soft); }
    footer { margin-top: 2.5rem; font-size: 0.8rem; color: var(--muted); }
  </style>
</head>
<body>
  <div class="wrap">
    <header>
      <span class="badge">Lab only — not production</span>
      <h1>IAE Central Corporate Mock Server</h1>
      <p class="lead">
        Mock sistem korporat pusat untuk laboratorium <strong>Enterprise Application Integration (EAI)</strong>.
        Hubungkan microservice Laravel Anda ke SSO REST, audit SOAP/XML, dan message broker RabbitMQ.
      </p>
    </header>

    <div class="cards">
      <div class="card">
        <h2><span class="dot sso"></span> Central SSO</h2>
        <p>REST JSON — autentikasi M2M (API key) dan end-user (KTP Digital Global).</p>
      </div>
      <div class="card">
        <h2><span class="dot soap"></span> Audit System</h2>
        <p>SOAP XML — submit log aktivitas generic lintas tema industri.</p>
      </div>
      <div class="card">
        <h2><span class="dot mq"></span> Message Broker</h2>
        <p>AMQP RabbitMQ — event bus <code>iae.central.exchange</code> (topic).</p>
      </div>
    </div>

    <section>
      <h2>API endpoints</h2>
      <table>
        <thead>
          <tr><th>Method</th><th>Path</th><th>Auth</th><th>Deskripsi</th></tr>
        </thead>
        <tbody>
          <tr>
            <td><span class="method get">GET</span></td>
            <td><code>/health</code></td>
            <td>—</td>
            <td>Health check JSON</td>
          </tr>
          <tr>
            <td><span class="method get">GET</span></td>
            <td><code>/api/v1/auth/jwks</code></td>
            <td>—</td>
            <td>Public keys (RS256) untuk verify JWT</td>
          </tr>
          <tr>
            <td><span class="method post">POST</span></td>
            <td><code>/api/v1/auth/token</code></td>
            <td>Body</td>
            <td>Token M2M atau end-user</td>
          </tr>
          <tr>
            <td><span class="method post">POST</span></td>
            <td><code>/soap/v1/audit</code></td>
            <td>Bearer M2M</td>
            <td>Audit XML generic</td>
          </tr>
          <tr>
            <td><span class="method post">POST</span></td>
            <td><code>/api/v1/messages/publish</code></td>
            <td>Bearer M2M</td>
            <td>Publish ke exchange RabbitMQ</td>
          </tr>
          <tr>
            <td><span class="method get">GET</span></td>
            <td><code>/board</code></td>
            <td>—</td>
            <td>Papan pengumuman — cek pesan sudah sampai di queue</td>
          </tr>
          <tr>
            <td><span class="method get">GET</span></td>
            <td><code>/api/admin/dashboard</code></td>
            <td><code>X-Admin-Key</code></td>
            <td>Log aktivitas HTML (dosen)</td>
          </tr>
        </tbody>
      </table>
    </section>

    <div class="links">
      <a href="/board">Papan pengumuman RabbitMQ</a>
      <a href="/health">Health check</a>
      <a href="/api/v1/auth/jwks">JWKS</a>
    </div>

    <footer>
      PHP 8.2 + Slim 4 · Dokumentasi lengkap di repositori (<code>README.md</code>, <code>TESTING.md</code>).
    </footer>
  </div>
</body>
</html>
HTML;
    }
}
