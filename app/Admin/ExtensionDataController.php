<?php

declare(strict_types=1);

namespace Pafish\Admin;

use Pafish\Services\ExtensionSchema;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** 扩展表归属只读页；不提供认领、清表或结构同步入口。 */
final class ExtensionDataController extends AdminController
{
    public function index(Request $request, Response $response): Response
    {
        $this->guardAdmin();
        $query = $request->getQueryParams();
        $kind = in_array($query['kind'] ?? '', ['plugin', 'theme'], true) ? $query['kind'] : '';
        $state = in_array($query['state'] ?? '', ['attention', 'retained'], true) ? $query['state'] : '';
        $all = ExtensionSchema::inventory();
        $existing = count(array_filter($all, static fn (array $table): bool => $table['exists']));
        $tables = array_values(array_filter($all, static function (array $table) use ($kind, $state): bool {
            return ($kind === '' || ($table['identity']['kind'] ?? null) === $kind)
                && ($state !== 'attention' || $table['attention'])
                && ($state !== 'retained' || in_array('retained', $table['statuses'], true));
        }));
        $response->getBody()->write($this->render('extension-data', [
            'tables' => $tables, 'existing' => $existing, 'missing' => count($all) - $existing,
            'kind' => $kind, 'state' => $state,
        ], '扩展数据表'));
        return $response;
    }
}
