# 升级与发行检查清单

这份清单用于发布前验证核心升级包，也记录近期线上故障的原因和处理方式。

## 发布包

- `app/Core/Version.php` 与 `composer.json` 的版本号一致。
- 包名为 `pafish-php-v{version}.zip`，所有条目位于唯一的 `pafish/` 顶层目录。
- 包含 `index.php`、`app/bootstrap.php`、`app/Services/Upgrade.php`、`composer.json`、`vendor/` 和模板引用的 `public/` 资源。
- 不包含站点 `config.php`、`runtime` 运行数据、`backups`、`public/uploads` 内容和本地开发工具。
- 上传或发布前检查包大小不超过 16 MB，解压后不超过 128 MB；拒绝 `..`、绝对路径、符号链接和顶层目录外的条目。

## 两种更新路径

- Gitee 更新：HTTPS 下载、SHA-256、ZIP 结构校验、数据库备份、文件备份、替换、迁移和失败回滚。
- 开发包上传：仅管理员可用，必须通过 CSRF 和同一套大小、版本、结构、备份、替换、回滚校验，不依赖 Gitee Release。
- 开发包可以使用与当前版本相同的版本号，方便在正式发布前反复验证修复；低于当前版本的包会拒绝执行。
- 两条路径最终调用同一个替换函数，不能为上传开发包复制另一套清理或回滚逻辑。

## 部署兼容性

- 项目根目录作为 Web 根时，系统资源必须生成 `/public/...`。
- `public/` 作为 Web 根时，系统资源必须生成 `/...`。
- 在 Apache、Nginx 和 PHP 内置开发服务器下分别验证后台 CSS/JS、favicon、Vditor、KaTeX、主题资源和上传资源。
- 升级清理必须保留 `config.php`、`runtime/`、`backups/`、`.well-known/`、`public/uploads/`；正常升级还保留已安装的 `themes/`、`plugins/`。

## 失败演练

- 在干净的旧版本副本上执行一次升级，检查首页、后台、媒体库、文章密码、SVG、主题图片上传、分页和数据库迁移。
- 模拟静态文件不可写、目录只读和正在使用的 `.well-known/acme-challenge`，确认错误包含具体路径且不会跟随符号链接。
- 模拟解压或迁移失败，确认旧文件和数据库均恢复，`runtime/.upgrade-bak-*` 仍可手动恢复。
- 更新失败后先恢复备份再重试，不要在备份未核对前连续覆盖站点。

## 已记录故障

- `v1.0.8` 曾把资源地址从 `/public/...` 改成 `/...`，破坏了项目根目录部署，导致 CSS/JS 全部 404。资源 URL 必须根据文档根判断，不能只在开发服务器上验证。
- 升级器曾把 `.well-known/acme-challenge` 当作普通根目录删除。证书验证目录由 Web 服务管理，即使权限正常也必须保留。
- 文档曾声称支持上传更新，但实现只有 Gitee 下载。现在工作台提供管理员上传开发包入口，供正式发布前在真实部署副本验证。
