# 测试说明

测试脚本使用真实 InnoDB 临时表（随机 `qa_core_<12位随机值>_` 前缀），只创建和清理自己的夹具表，不会改写应用业务数据；脚本拒绝通过 Web 执行。

## 运行前提

- PHP CLI 8.1+，启用 `pdo_mysql`。
- 可用的本地 MySQL，且 `runtime/config.php`（或旧版根目录 `config.php`）中的数据库账号有创建/删除表的权限。
- 先启动本地数据库；数据库不通时脚本会在连接阶段失败。

## 运行

```bash
php tests/core-foundation.php
php tests/markdown-rendering.php
php tests/http-stack.php
php tests/run.php
```

每个脚本独立运行，结束后清理自己创建的测试表。并发类脚本会再起子进程，连接同一组夹具表。`run.php` 顺序运行功能测试并汇总失败；安装器和性能测试默认跳过，可分别用 `--include-installer` 与 `--include-performance` 加入。

## 脚本清单

| 脚本 | 覆盖内容 |
| --- | --- |
| `core-foundation.php` | 核心数据层：事务/保存点、SQL 与 PHP 迁移重试/互斥、软删关系、用户删除转移、真实外键 |
| `markdown-rendering.php` | 正文渲染：转义、脚注、任务列表、代码块、HTML 净化 |
| `media-references.php` | 媒体引用索引、实体元数据命名空间/迁移/生命周期、并发保存/删除、备份字节往返 |
| `core-lifecycle.php` | 公开访问、评论竞态、限流与备份生命周期 |
| `content-controls.php` | 文章评论开关、栏目 SEO、分类封面、友链图标等内容控制字段 |
| `content-list-contract.php` | 内容列表查询的语义契约 |
| `query-contract.php` / `query-performance.php` | 通用查询契约与真实 InnoDB 查询计划 |
| `list-query-contract.php` / `list-query-performance.php` | 首页/分类列表查询语义、索引与大数据量查询计划 |
| `schema-contract.php` | 安装基线、迁移后结构与当前库的结构契约（支持 `--report`） |
| `installer.php` | 安装向导回归：在随机临时数据库/目录中实际执行安装器并清理；可用 `PAFISH_INSTALL_TEST_USER` / `PAFISH_INSTALL_TEST_PASSWORD` 指定测试账号 |
| `deployment-compat.php` | 两种文档根布局下的静态资源与部署兼容 |
| `auth-security.php` | 登录、会话、权限与安全边界 |
| `app-tokens.php` | APP 用户令牌哈希、设备撤销、账号状态、角色即时生效、设备上限与旧插件卸载 |
| `account-center.php` | 固定角色权限、默认后台中心与主题中心、个人数据隔离/分页、头像校验、编辑微语归属；`--snapshots` 输出本地视觉检查夹具 |
| `error-handler.php` | 普通 404 不写应用错误日志，HTTP 状态、API JSON 与真实异常日志保持正确；使用独立临时日志目录 |
| `http-stack.php` | 真实 Slim `App::handle()` 中间件栈：子目录路径、后台登录重定向、POST CSRF、站内重定向和 API 404 |
| `cache-contract.php` / `cache-integration.php` / `cache-performance.php` | 文件缓存契约、事务集成与回填性能 |
| `transaction-cache.php` | 事务与缓存失效的交互 |
| `extension-inventory.php` / `extension-references.php` | 扩展表归属盘点、references 外键声明与同步 |
| `extension-core.php` | 统一 extension.php 入口的失败隔离、幂等启动与运行时清理 |

## 注意

- 性能脚本的耗时只代表本地合成数据的热缓存结果，不代表线上整页或并发耗时。
- 需要生成对比报告时，按脚本自身输出的参数提示运行（例如 `schema-contract.php --report`）。
- 测试通过不等同于浏览器端视觉验收；后台 UI 变更仍需单独做交互检查。
