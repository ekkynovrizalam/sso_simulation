<?php

declare(strict_types=1);

namespace Iae\Central\Services;

use DOMDocument;
use DOMXPath;

final class SoapAuditService
{
    private const ALLOWED_CONTENT_TYPES = [
        'text/xml',
        'application/soap+xml',
        'application/xml',
    ];

    public function isXmlContentType(?string $contentType): bool
    {
        if ($contentType === null || $contentType === '') {
            return false;
        }

        $primary = strtolower(trim(explode(';', $contentType)[0]));

        return in_array($primary, self::ALLOWED_CONTENT_TYPES, true);
    }

    public function isJsonContentType(?string $contentType): bool
    {
        if ($contentType === null) {
            return false;
        }

        $primary = strtolower(trim(explode(';', $contentType)[0]));

        return $primary === 'application/json' || str_ends_with($primary, '+json');
    }

    /**
     * Generic audit schema for all industry themes.
     *
     * @return array{team_id: string, activity_name: string, log_content: string}
     * @throws \InvalidArgumentException
     */
    public function parseAuditRequest(string $xmlBody): array
    {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);

        if (!$doc->loadXML($xmlBody)) {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            throw new \InvalidArgumentException('Invalid XML document.');
        }

        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('soap', 'http://schemas.xmlsoap.org/soap/envelope/');
        $xpath->registerNamespace('iae', 'http://iae.central/audit');

        $teamId = $this->firstNodeValue($xpath, [
            '//iae:TeamID',
            '//TeamID',
            '//*[local-name()="TeamID"]',
        ]);
        $activityName = $this->firstNodeValue($xpath, [
            '//iae:ActivityName',
            '//ActivityName',
            '//*[local-name()="ActivityName"]',
        ]);
        $logContent = $this->firstNodeValue($xpath, [
            '//iae:LogContent',
            '//LogContent',
            '//*[local-name()="LogContent"]',
        ], allowEmpty: false);

        if ($teamId === null || $activityName === null || $logContent === null) {
            throw new \InvalidArgumentException(
                'Missing required fields: TeamID, ActivityName, LogContent.'
            );
        }

        return [
            'team_id' => $teamId,
            'activity_name' => $activityName,
            'log_content' => $logContent,
        ];
    }

    public function generateReceiptNumber(): string
    {
        return 'IAE-LOG-2026-' . strtoupper(bin2hex(random_bytes(4)));
    }

    public function buildSuccessResponse(string $receiptNumber): string
    {
        $status = htmlspecialchars('SUCCESS', ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $receipt = htmlspecialchars($receiptNumber, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:iae="http://iae.central/audit">
  <soap:Body>
    <iae:AuditResponse>
      <iae:Status>{$status}</iae:Status>
      <iae:ReceiptNumber>{$receipt}</iae:ReceiptNumber>
    </iae:AuditResponse>
  </soap:Body>
</soap:Envelope>
XML;
    }

    public function buildFault(string $faultCode, string $faultString): string
    {
        $code = htmlspecialchars($faultCode, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $message = htmlspecialchars($faultString, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <soap:Fault>
      <faultcode>{$code}</faultcode>
      <faultstring>{$message}</faultstring>
    </soap:Fault>
  </soap:Body>
</soap:Envelope>
XML;
    }

    /**
     * @param list<string> $queries
     */
    private function firstNodeValue(DOMXPath $xpath, array $queries, bool $allowEmpty = true): ?string
    {
        foreach ($queries as $query) {
            $nodes = $xpath->query($query);
            if ($nodes !== false && $nodes->length > 0) {
                $value = trim($nodes->item(0)?->textContent ?? '');
                if ($value !== '' || $allowEmpty) {
                    return $value;
                }
            }
        }

        return null;
    }
}
