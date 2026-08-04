<?php

declare(strict_types=1);

namespace Pafish\Admin;

use Pafish\Core\DB;
use Pafish\Services\Plugin;
use Pafish\Services\Settings;
use Pafish\Services\Slug;
use Pafish\Services\Theme;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 页面管理（对齐 Node app/admin/pages/ 系列）
 * - 列表 / 新建 / 编辑 / 保存；模板白名单校验；硬删除（无回收站）
 * - "设为首页"写入 settings.home_page_id（前台首页优先渲染该页面）
 */
final class PagesController extends AdminController
{
    /** 页面模板选项：default + 激活主题 + 激活插件（对齐 Node getPageTemplateOptions 三层来源） */
    public static function templateOptions(): array
    {
        $options = ['default' => '默认模板'];
        foreach (Theme::pageTemplates(Theme::active()) as $tpl) {
            $options[$tpl['name']] = $tpl['title'];
        }
        foreach (Plugin::activeNames() as $name) {
            $desc = Plugin::describe($name);
            if ($desc['error'] !== null) {
                continue;
            }
            foreach (($desc['manifest']['pageTemplates'] ?? []) as $tpl) {
                $options[$tpl['name']] = $tpl['title'];
            }
        }
        return $options;
    }

    /** 列表（对齐 Node pages-manager） */
    public function index(Request $request, Response $response): Response
    {
        $this->guardCanManage();
        $pages = DB::fetchAll('SELECT * FROM pages ORDER BY updated_at DESC, id DESC');
        $response->getBody()->write($this->render('pages', [
            'pages' => $pages,
            'homePageId' => (string) Settings::get('home_page_id', ''),
            'templateOptions' => self::templateOptions(),
        ], '页面管理'));
        return $response;
    }

    /** 新建编辑器 */
    public function createEditor(Request $request, Response $response): Response
    {
        $this->guardCanManage();
        $response->getBody()->write($this->render('page-editor', [
            'isEdit' => false,
            'page' => null,
            'templateOptions' => self::templateOptions(),
        ], '新建页面'));
        return $response;
    }

    /** 编辑编辑器 */
    public function editEditor(Request $request, Response $response, array $args): Response
    {
        $this->guardCanManage();
        $page = DB::fetchOne('SELECT * FROM pages WHERE id = ?', [(int) ($args['id'] ?? 0)]);
        if (!$page) {
            $this->flash('error', '页面不存在');
            return $this->redirect($response, '/admin/pages');
        }
        $response->getBody()->write($this->render('page-editor', [
            'isEdit' => true,
            'page' => $page,
            'templateOptions' => self::templateOptions(),
        ], '编辑页面'));
        return $response;
    }

    /** 保存（新建/更新共用；对齐 createPage/updatePage） */
    public function save(Request $request, Response $response, array $args): Response
    {
        $this->guardCanManage();
        $body = $request->getParsedBody() ?? [];
        $id = isset($args['id']) ? (int) $args['id'] : 0;

        try {
            $result = $this->savePage($body, $id > 0 ? $id : null);
        } catch (\RuntimeException $e) {
            if ($this->isAjax($request)) {
                return $this->json($response, ['error' => $e->getMessage()], 400);
            }
            $this->flash('error', $e->getMessage());
            return $this->redirect($response, $id > 0 ? "/admin/pages/{$id}/edit" : '/admin/pages/new');
        }

        if ($this->isAjax($request)) {
            return $this->json($response, ['ok' => true, 'id' => $result['id']]);
        }
        $this->flash('success', $result['created'] ? '页面已保存' : '页面已更新');
        return $this->redirect($response, "/admin/pages/{$result['id']}/edit");
    }

    /** 删除（硬删除，无回收站；删除首页页面时同步清空 home_page_id） */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $this->guardCanManage();
        $id = (int) ($args['id'] ?? 0);
        if ((string) Settings::get('home_page_id', '') === (string) $id) {
            DB::execute("DELETE FROM settings WHERE `key` = 'home_page_id'");
        }
        DB::execute('DELETE FROM pages WHERE id = ?', [$id]);
        if ($this->isAjax($request)) {
            return $this->json($response, ['ok' => true]);
        }
        $this->flash('success', '页面已删除');
        return $this->redirect($response, '/admin/pages');
    }

    /** 设为首页 / 取消（写入 settings.home_page_id，对齐 Node setHomePage/unsetHomePage） */
    public function setHome(Request $request, Response $response): Response
    {
        $this->guardCanManage();
        $body = $request->getParsedBody() ?? [];
        $id = (int) ($body['id'] ?? 0);
        $set = (bool) ($body['set'] ?? false);

        if ($set) {
            $page = DB::fetchOne('SELECT id FROM pages WHERE id = ?', [$id]);
            if (!$page) {
                return $this->json($response, ['error' => '页面不存在'], 404);
            }
            Settings::set('home_page_id', (string) $id);
        } else {
            DB::execute("DELETE FROM settings WHERE `key` = 'home_page_id'");
        }
        return $this->json($response, ['ok' => true]);
    }

    /** 保存逻辑：校验 + slug 去冲突 + 写入 */
    private function savePage(array $body, ?int $id = null): array
    {
        $title = trim((string) ($body['title'] ?? ''));
        if ($title === '') {
            throw new \RuntimeException('标题不能为空');
        }
        if (mb_strlen($title) > 100) {
            throw new \RuntimeException('标题不能超过 100 字');
        }

        // slug：用户输入优先，否则由标题生成；冲突自动 -2/-3（排除自身）
        $slug = trim((string) ($body['slug'] ?? ''));
        if ($slug === '') {
            $slug = Slug::slugify($title);
        }
        if (mb_strlen($slug) > 100) {
            throw new \RuntimeException('页面地址不能超过 100 字');
        }
        $slug = Slug::resolveUnique($slug, static function (string $candidate) use ($id): bool {
            return DB::fetchOne(
                'SELECT id FROM pages WHERE slug = ? AND (? IS NULL OR id <> ?) LIMIT 1',
                [$candidate, $id, $id]
            ) !== null;
        });

        $status = ($body['status'] ?? '') === 'PUBLISHED' ? 'PUBLISHED' : 'DRAFT';

        // 模板白名单：小写字母/数字/连字符，1-40 位；"default" 为系统默认模板保留名
        $template = trim((string) ($body['template'] ?? ''));
        if ($template === '') {
            $template = 'default';
        }
        if (!preg_match('/^[a-z0-9-]{1,40}$/', $template)) {
            throw new \RuntimeException('模板名称只能包含小写字母、数字和连字符');
        }

        $content = (string) ($body['content'] ?? '');

        if ($id !== null) {
            DB::execute(
                'UPDATE pages SET title = ?, slug = ?, content = ?, status = ?, template = ?, updated_at = NOW() WHERE id = ?',
                [$title, $slug, $content, $status, $template, $id]
            );
            return ['id' => $id, 'created' => false];
        }

        // 新建：发布时记录 published_at（对齐 Node publishedAt 语义）
        $publishedAt = $status === 'PUBLISHED' ? date('Y-m-d H:i:s') : null;
        DB::execute(
            'INSERT INTO pages (title, slug, content, status, template, published_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [$title, $slug, $content, $status, $template, $publishedAt]
        );
        return ['id' => (int) DB::pdo()->lastInsertId(), 'created' => true];
    }
}
