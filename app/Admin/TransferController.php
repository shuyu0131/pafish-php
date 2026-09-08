<?php

declare(strict_types=1);

namespace Pafish\Admin;

use Pafish\Services\ContentTransfer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class TransferController extends AdminController
{
    public function index(Request $request, Response $response): Response
    {
        $this->guardCapability('transfer.manage');
        $response->getBody()->write($this->render('transfer', [], '内容迁移'));
        return $response;
    }

    public function export(Request $request, Response $response): Response
    {
        $this->guardCapability('transfer.manage');
        $json = json_encode(ContentTransfer::export(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $response->getBody()->write((string) $json);
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Content-Disposition', 'attachment; filename="pafish-content-' . date('Ymd-His') . '.json"');
    }

    public function import(Request $request, Response $response): Response
    {
        $this->guardCapability('transfer.manage');
        $body = $request->getParsedBody() ?? [];
        $raw = trim((string) ($body['json'] ?? ''));
        $files = $request->getUploadedFiles();
        if ($raw === '' && isset($files['file'])) $raw = (string) $files['file']->getStream();
        $payload = json_decode($raw, true);
        if (!is_array($payload)) return $this->json($response, ['error' => 'JSON 内容无效'], 400);
        try { return $this->json($response, ['ok' => true, 'counts' => ContentTransfer::import($payload, (string) ($body['mode'] ?? 'skip'))]); }
        catch (\Throwable $e) { return $this->json($response, ['error' => $e->getMessage()], 400); }
    }
}
