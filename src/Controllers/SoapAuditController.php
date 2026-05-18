<?php

declare(strict_types=1);

namespace Iae\Central\Controllers;

use Iae\Central\Services\ActivityLogger;
use Iae\Central\Services\AuthService;
use Iae\Central\Services\SoapAuditService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class SoapAuditController
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly SoapAuditService $soapService,
        private readonly ActivityLogger $logger,
    ) {
    }

    public function audit(Request $request, Response $response): Response
    {
        $contentType = $request->getHeaderLine('Content-Type');

        if ($this->soapService->isJsonContentType($contentType)) {
            return $this->soapFault($response, 'soap:Client', 'Unsupported Media Type: JSON is not accepted. Use text/xml or application/soap+xml.', 415);
        }

        if (!$this->soapService->isXmlContentType($contentType)) {
            return $this->soapFault($response, 'soap:Client', 'Unsupported Media Type. Expected text/xml or application/soap+xml.', 415);
        }

        $token = AuthService::extractBearerToken($request->getHeaderLine('Authorization'));
        if ($token === null) {
            return $this->soapFault($response, 'soap:Client', 'Unauthorized: Missing Bearer token.', 401);
        }

        $identity = $this->authService->validateToken($token);
        if ($identity === null) {
            return $this->soapFault($response, 'soap:Client', 'Unauthorized: Invalid or expired Bearer token.', 401);
        }

        $xmlBody = (string) $request->getBody();
        if (trim($xmlBody) === '') {
            return $this->soapFault($response, 'soap:Client', 'Empty SOAP request body.', 400);
        }

        try {
            $audit = $this->soapService->parseAuditRequest($xmlBody);
        } catch (\InvalidArgumentException $e) {
            return $this->soapFault($response, 'soap:Client', $e->getMessage(), 400);
        }

        $receipt = $this->soapService->generateReceiptNumber();

        $this->logger->log(
            $this->authService->logSubject($identity),
            'soap_audit',
            $this->authService->logDisplayName($identity),
            json_encode([
                'token_type' => $identity['token_type'],
                'team_id' => $audit['team_id'],
                'activity_name' => $audit['activity_name'],
                'log_content' => $audit['log_content'],
                'receipt_number' => $receipt,
            ], JSON_THROW_ON_ERROR)
        );

        $xml = $this->soapService->buildSuccessResponse($receipt);
        $response->getBody()->write($xml);

        return $response
            ->withHeader('Content-Type', 'application/soap+xml; charset=utf-8')
            ->withStatus(200);
    }

    private function soapFault(Response $response, string $code, string $message, int $status): Response
    {
        $xml = $this->soapService->buildFault($code, $message);
        $response->getBody()->write($xml);

        return $response
            ->withHeader('Content-Type', 'application/soap+xml; charset=utf-8')
            ->withStatus($status);
    }
}
