# pafish（PHP 版）

极简博客系统，Node 版（Next.js）的 PHP 复刻：功能、界面与数据层完全对齐 v1.1.0。
面向虚拟主机 / 宝塔等 PHP 环境，**零命令行**安装。

## 技术栈

- PHP 8.1+（pdo_mysql / gd / zip / mbstring / openssl / json）
- MySQL 5.7.6+（推荐 8.0）
- Slim 4（微框架）+ PHP-DI + PDO
- 原生 PHP 模板（无模板引擎；主题可用 PHP 模板文件覆盖）

## 安装

1. 将项目上传到网站根目录（发布包已包含 `vendor/`，无需 Composer）
2. 访问 `http://你的域名/install.php`，按向导填写数据库信息
3. 完成安装后删除 `install.php`，打开首页即见站点

命令行开发时也可用 PHP 内置服务器：

```bash
php -S localhost:8000
```

> 生产环境推荐 Apache（`.htaccess` 已内置伪静态）或 Nginx（`try_files $uri $uri/ /index.php?$query_string;`）。
> 不支持伪静态的主机可在 `config.php` 关闭 `pretty_urls`，自动切换为 `index.php?p=xxx` 路由。

## 默认账号（安装向导种子数据）

| 角色 | 用户名 | 密码 |
|---|---|---|
| 管理员 | admin | Admin@12345 |
| 编辑 | editor | Editor@12345 |

## 目录结构

```
├── index.php          # 入口
├── install.php        # Web 安装向导（安装后请删除）
├── cron.php           # 定时发布（计划任务可选）
├── config.php         # 安装生成（勿入库，.htaccess 已禁止访问）
├── app/               # 应用代码（Core/Services/Http/Views）
├── admin/             # 后台
├── themes/            # 主题（theme.json + theme.css + 可选 PHP 模板）
├── plugins/           # 插件
├── public/uploads/    # 上传文件
├── backups/           # 数据库备份
├── runtime/           # 运行期缓存（验证码/限速/日志）
└── migrations/        # 增量迁移
```

## 主题

- `themes/{name}/theme.json`：manifest + 设置 schema（8 类型：text/textarea/checkbox/switcher/select/radio/color/image）
- `themes/{name}/theme.css`：语义 CSS 变量（`--bg/--fg/--accent/...`），与 Node 版主题包 1:1 兼容
- `themes/{name}/header.php` 等：**可选 PHP 模板文件**，覆盖系统模板（WordPress 式）

## 许可证

MIT
