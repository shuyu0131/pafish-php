<?php

declare(strict_types=1);

namespace Pafish\Admin;

use Pafish\Services\MicroStatuses;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** 微语后台管理；复用 posts.manage，不新增 capability。 */
final class MicroController extends AdminController
{
    public function index(Request $request, Response $response): Response
    {
        $this->guardCanManage();
        $query = $request->getQueryParams();
        $status = in_array((string) ($query['status'] ?? ''), [MicroStatuses::DRAFT, MicroStatuses::PUBLISHED], true)
            ? (string) $query['status'] : '';
        $q = trim((string) ($query['q'] ?? ''));
        $data = MicroStatuses::adminPage(max(1, (int) ($query['page'] ?? 1)), 20, $status, $q);
        $response->getBody()->write($this->render('micro', [
            'mode' => 'list',
            'items' => $data['items'],
            'total' => $data['total'],
            'page' => $data['page'],
            'totalPages' => $data['totalPages'],
            'per' => $data['perPage'],
            'filters' => ['status' => $status, 'q' => $q],
            'micro' => null,
            'isEdit' => false,
        ], '微语'));
        return $response;
    }

    public function createEditor(Request $request, Response $response): Response
    {
        $this->guardCanManage();
        return $this->editorResponse($response, null);
    }

    public function editEditor(Request $request, Response $response, array $args): Response
    {
        $this->guardCanManage();
        $micro = MicroStatuses::find((int) ($args['id'] ?? 0));
        if ($micro === null) {
            $this->flash('error', '微语不存在');
            return $this->redirect($response, '/admin/micro');
        }
        return $this->editorResponse($response, $micro);
    }

    public function save(Request $request, Response $response, array $args = []): Response
    {
        $this->guardCanManage();
        // 整个表单数组原样交给服务层：核心只取自己认识的键，
        // 其余键（扩展注入的字段）会通过 before_micro_save / after_micro_save 的 input 传给扩展。
        $body = $request->getParsedBody() ?? [];
        $id = isset($args['id']) ? (int) $args['id'] : 0;
        try {
            $micro = MicroStatuses::save($body, $id > 0 ? $id : null);
        } catch (\RuntimeException $e) {
            if ($this->isAjax($request)) {
                return $this->json($response, ['error' => $e->getMessage()], 400);
            }
            $this->flash('error', $e->getMessage());
            return $this->redirect($response, $id > 0 ? '/admin/micro/' . $id . '/edit' : '/admin/micro/new');
        }
        if ($this->isAjax($request)) {
            return $this->json($response, ['ok' => true, 'id' => (string) $micro['id']]);
        }
        $this->flash('success', '微语已保存');
        return $this->redirect($response, '/admin/micro/' . (int) $micro['id'] . '/edit');
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $this->guardCanManage();
        $ok = MicroStatuses::delete((int) ($args['id'] ?? 0));
        if ($this->isAjax($request)) {
            return $this->json($response, ['ok' => $ok], $ok ? 200 : 404);
        }
        $this->flash($ok ? 'success' : 'error', $ok ? '微语已删除' : '微语不存在');
        return $this->redirect($response, '/admin/micro');
    }

    private function editorResponse(Response $response, ?array $micro): Response
    {
        $response->getBody()->write($this->render('micro', [
            'mode' => 'editor',
            'micro' => $micro,
            'isEdit' => $micro !== null,
            'items' => [],
            'filters' => [],
            // Vditor 样式与文章、页面编辑器共用同一份
            'headExtra' => editor_head_extra(),
        ], $micro === null ? '新建微语' : '编辑微语'));
        return $response;
    }
}
