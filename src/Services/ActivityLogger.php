<?php

declare(strict_types=1);

namespace Iae\Central\Services;

use PDO;

final class ActivityLogger
{
    private PDO $pdo;

    public function __construct(string $dbPath)
    {
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->pdo = new PDO('sqlite:' . $dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->migrate();
    }

    private function migrate(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS activity_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                api_key TEXT NOT NULL,
                student_name TEXT,
                event_type TEXT NOT NULL,
                details TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        SQL);
    }

    public function log(string $apiKey, string $eventType, ?string $studentName = null, ?string $details = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO activity_logs (api_key, student_name, event_type, details) VALUES (:api_key, :student_name, :event_type, :details)'
        );
        $stmt->execute([
            'api_key' => $apiKey,
            'student_name' => $studentName,
            'event_type' => $eventType,
            'details' => $details,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function recent(int $limit = 200): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM activity_logs ORDER BY id DESC LIMIT :limit'
        );
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string, int> */
    public function summaryByApiKey(): array
    {
        $stmt = $this->pdo->query(
            "SELECT api_key,
                    SUM(CASE WHEN event_type IN ('sso', 'sso_m2m', 'sso_user') THEN 1 ELSE 0 END) AS sso_count,
                    SUM(CASE WHEN event_type = 'soap_audit' THEN 1 ELSE 0 END) AS soap_count,
                    SUM(CASE WHEN event_type = 'rabbitmq' THEN 1 ELSE 0 END) AS rabbitmq_count
             FROM activity_logs
             GROUP BY api_key
             ORDER BY api_key"
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
