# 常见问题（FAQ）

## 安装 / 部署

**Q：安装向导提示「PHP post_max_size 需 ≥ 16M」是警告，可以继续吗？**
可以。博客核心功能（发文、上传图片、评论等）不受影响；只有「应用商店 / 主题插件 zip 安装」会失败，
因为包上限是 10MB。建议按警告提示调大并重启 PHP（宝塔：软件商店 → PHP → 设置 → 配置修改）。

**Q：提示「根目录不可写」？**
Linux 下给站点目录执行 `chown -R www:www /www/wwwroot/你的站点`（宝塔可在文件管理器右键 → 权限设置），
并确保 `runtime/`、`backups/`、`public/uploads/` 三个目录可写。

**Q：不支持伪静态怎么办？**
安装向导中取消勾选「启用伪静态」，或在 `config.php` 把 `pretty_urls` 设为 `false`。
链接会自动切换为 `index.php?p=文章slug` 形式，功能不受影响。

**Q：`/post/...` 返回 Nginx 404，但 `index.php?p=post/...` 正常？**
这是伪静态规则没有生效。Nginx 的 `location /` 需要将不存在的路径回退到 `/index.php?$query_string`，
不要使用 `try_files $uri =404` 处理整个站点。修改后执行 `nginx -t` 并重载 Nginx。若服务器确实不能
配置该转发，再关闭 `pretty_urls` 使用查询串链接。

**Q：能从其他系统迁移数据吗？**
两版数据表结构完全一致（14 张表 + settings 键值），理论可用 mysqldump 导出的 SQL 直接导入
PHP 版的库后改 `config.php` 指向该库。正式迁移前请完整备份，并确认两版 schema 版本一致。

## 内容 / 前台

**Q：发布了文章但前台不显示？**
排查顺序：
1. 文章状态是否为「已发布」（后台文章管理 → 状态）
2. 检查站点时区设置与服务器 MySQL 时区（PHP 版已自动将会话时区对齐站点时区；若服务器与站点时区
   差异超过 24 小时且「今天」的文章不显示，请在站点设置中确认时区正确）
3. 定时文章需等待发布时间到达（见下）

**Q：定时发布的文章会提前泄露吗？**
不会。即使未配置任何计划任务，前台查询也强制要求 `status='PUBLISHED'` 且 `published_at <= NOW()`，
未到时间的定时文章不会被任何前台页面/API/RSS 输出。

**Q：搜索中文没结果？**
全文搜索依赖 MySQL FULLTEXT ngram 解析器（5.7.6+）。老版本或未建索引时自动回退 LIKE 单字搜索。
若刚导入旧数据，可在后台重建索引或检查 `ft_posts_search` 索引是否存在。

**Q：评论需要填写什么？**
评论可选填写昵称/邮箱；登录用户免验证码；游客会显示图形验证码（后台可关闭）。
同 IP 5 秒内只能发一条评论，黑名单 IP 直接 403。

## 后台 / 功能

**Q：媒体上传失败？**
检查 `upload_max_filesize`（建议 ≥12M，单图超过此值会失败）。已启用云存储插件时，上传失败会
自动回退本地磁盘，可在插件设置确认 endpoint/密钥是否正确。

**Q：备份按钮提示 mysqldump 不可用？**
服务器未安装 mysqldump 或 PHP 禁用了 exec 时，自动回退纯 PHP 逐表导出，功能不受影响；
超大数据库（数百 MB）建议使用宝塔自带的数据库备份。

**Q：SMTP 发信失败？**
站点设置 → SMTP：确认服务器地址/端口（465 走 SSL、587 走 STARTTLS）、账号密码、发件人；
点「测试发送」看具体错误。未配置 SMTP 时系统尝试 PHP `mail()`，虚拟主机可能不可用。

**Q：忘记管理员密码？**
在服务器上用 PHP 命令行执行（替换用户名与密码）：
```php
php -r 'echo password_hash("新密码", PASSWORD_BCRYPT);'
```
把输出粘贴到数据库 `users` 表对应用户的 `password` 字段（或直接用宝塔 phpMyAdmin 修改）。

## 安全

**Q：为什么要求删除 install.php？**
安装向导无鉴权，任何人访问都可能重装覆盖配置。安装完成后请务必删除。

**Q：config.php 会被下载吗？**
Apache 下已被 `.htaccess` 禁止；Nginx 请确认包含 `location ~ ^/(config\.php|runtime/|backups/) { deny all; }`。

**Q：开放 API 如何使用？**
后台站点设置 → 开放 API：启用并生成 Key。请求时带 `X-API-Key: <你的Key>` 头，接口为
`/api/v1/posts`、`/api/v1/posts/{slug}`、`/api/v1/categories`、`/api/v1/tags`、`/api/v1/comments`。
密钥比对使用常量时间比较，防止时序攻击。
