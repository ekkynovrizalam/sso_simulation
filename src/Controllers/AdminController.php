<?php

declare(strict_types=1);

namespace Iae\Central\Controllers;

use Iae\Central\Services\ActivityLogger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AdminController
{
    public function __construct(
        private readonly ActivityLogger $logger,
        private readonly string $adminKey,
    ) {
    }

    public function dashboard(Request $request, Response $response): Response
    {
        $provided = $request->getHeaderLine('X-Admin-Key');
        if ($provided === '' || !hash_equals($this->adminKey, $provided)) {
            $response->getBody()->write('<h1>403 Forbidden</h1><p>Invalid or missing X-Admin-Key header.</p>');

            return $response
                ->withHeader('Content-Type', 'text/html; charset=utf-8')
                ->withStatus(403);
        }

        $summary = $this->logger->summaryByApiKey();
        $logs = $this->logger->recent(100);

        $html = $this->renderDashboard($summary, $logs);
        $response->getBody()->write($html);

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /** @param list<array<string, mixed>> $summary @param list<array<string, mixed>> $logs */
    private function renderDashboard(array $summary, array $logs): string
    {
        $summaryRows = '';
        foreach ($summary as $row) {
            $summaryRows .= sprintf(
                '<tr><td>%s</td><td>%d</td><td>%d</td><td>%d</td></tr>',
                htmlspecialchars((string) $row['api_key'], ENT_QUOTES, 'UTF-8'),
                (int) $row['sso_count'],
                (int) $row['soap_count'],
                (int) $row['rabbitmq_count'],
            );
        }

        if ($summaryRows === '') {
            $summaryRows = '<tr><td colspan="4">No activity yet.</td></tr>';
        }

        $logRows = '';
        foreach ($logs as $log) {
            $logRows .= sprintf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                htmlspecialchars((string) $log['created_at'], ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string) $log['api_key'], ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string) ($log['student_name'] ?? '—'), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string) $log['event_type'], ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string) ($log['details'] ?? ''), ENT_QUOTES, 'UTF-8'),
            );
        }

        if ($logRows === '') {
            $logRows = '<tr><td colspan="5">No log entries yet.</td></tr>';
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>IAE Central Mock — Admin Dashboard</title>
  <style>
    body { font-family: system-ui, sans-serif; margin: 2rem; background: #f6f8fa; color: #1f2328; }
    h1 { margin-bottom: 0.25rem; }
    p.sub { color: #656d76; margin-top: 0; }
    table { width: 100%; border-collapse: collapse; background: #fff; margin: 1.5rem 0; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
    th, td { border: 1px solid #d0d7de; padding: 0.6rem 0.75rem; text-align: left; font-size: 0.9rem; }
    th { background: #24292f; color: #fff; }
    tr:nth-child(even) { background: #f6f8fa; }
    .badge { display: inline-block; padding: 0.15rem 0.5rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600; }
    .sso { background: #ddf4ff; color: #0969da; }
    .soap_audit { background: #fff8c5; color: #9a6700; }
    .rabbitmq { background: #dafbe1; color: #1a7f37; }
  </style>
</head>
<body>
  <h1>Central Corporate System — Activity Dashboard</h1>
  <p class="sub">Instructor view · SSO · SOAP Audit · RabbitMQ publish events</p>

  <h2>Summary by Subject (API Key / Email)</h2>
  <table>
    <thead>
      <tr><th>Subject</th><th>SSO</th><th>SOAP Audit</th><th>RabbitMQ</th></tr>
    </thead>
    <tbody>{$summaryRows}</tbody>
  </table>

  <h2>Recent Activity (last 100)</h2>
  <table>
    <thead>
      <tr><th>Time (UTC)</th><th>Subject</th><th>Name</th><th>Event</th><th>Details</th></tr>
    </thead>
    <tbody>{$logRows}</tbody>
  </table>
</body>
</html>
HTML;
    }
}
