# 宝塔面板部署指南（pafish PHP 版）

本指南适用于宝塔 Linux 面板（BT Panel）。虚拟主机用户可跳过，直接按 README 的
「方式一：发布包」操作即可（虚拟主机通常已内置 Apache/Nginx 伪静态支持）。

## 一、建站与运行环境

1. 宝塔面板 → 网站 → 添加站点（PHP 版本选 **8.1+**，如 8.2/8.3）
2. 若站点目录不是默认的 `/www/wwwroot/你的域名`，记下实际路径
3. 软件商店确认已安装 **PHP 8.1+**，并开启以下扩展：`pdo_mysql`、`gd`、`zip`、`mbstring`、`openssl`、`fileinfo`
4. 数据库：添加 MySQL 数据库（5.7.6+，推荐 8.0），记下**数据库名 / 用户名 / 密码**

## 二、上传与解压

1. 下载 `pafish-php-vX.Y.Z.zip` 发布包
2. 宝塔文件管理器进入 `/www/wwwroot/你的域名/`，上传 zip
3. 右键 zip → 解压 → 得到 `pafish/` 目录
4. 进入 `pafish/`，**全选 → 剪切**，回到站点根目录**粘贴**（此时站点根目录就是 pafish 的全部文件）
5. 删除站点根目录下残留的空 `pafish/` 目录和 zip 包

> 也可以在添加站点时直接把网站根目录设为 `pafish/`，免去移动步骤。

## 三、PHP 上传限制（应用商店必需）

主题/插件 zip 包上限 10MB，需确保：

- `post_max_size` ≥ 16M
- `upload_max_filesize` ≥ 12M

操作：软件商店 → PHP → 设置 → 「配置修改」→ 搜索 `post_max_size` 与 `upload_max_filesize` 修改后保存；
或在「上传限制」页直接调大。修改后 **PHP 需要重启**（设置页右下角「重启」按钮）。
不满足时博客核心功能不受影响，安装向导会以警告形式提示。

## 四、安装向导

1. 浏览器访问 `http://你的域名/install.php`
2. 环境检查页：全部 ✓ 后点「下一步」
3. 填写数据库连接（第二步创建的库）与站点信息、管理员账号
4. 安装完成 → **立即删除 `install.php`**
5. 打开首页即可访问；`http://你的域名/admin/` 进入后台

## 五、定时发布（计划任务，可选）

宝塔面板 → 计划任务 → 添加任务：

- 类型：**Shell 脚本**
- 名称：pafish 定时发布
- 执行周期：**每 1 分钟**（N 分钟）
- 脚本内容：`php /www/wwwroot/你的域名/cron.php`

保存即可。没有配置计划任务时，前台请求也会低频自动发布到期文章，定时功能不会失效。

## 六、伪静态（Nginx）

伪静态的关键是：不存在的前台路径必须交给项目根目录的 `index.php`。宝塔建站通常已配置
`try_files`，若站点使用了其他 Nginx 模板，请确认包含下面规则（root 必须是项目根目录，不能指向
`public/`）：

```nginx
# 伪静态：所有前台路径交给 index.php
location / {
    try_files $uri $uri/ /index.php?$query_string;
}

# 主题资源（推荐显式添加，兼容部分宝塔模板的 error_page 404 回退）
location ^~ /theme-assets/ {
    try_files $uri /index.php?p=$uri&$query_string;
}

# 静态资源在 public/ 下，对外保持根路径（css/ js/ uploads/ vendor/）。
# v0.1.2+ 已内置 PHP 兜底：仅上面一条 try_files 即可让 /css/… 等资源正常加载
#（框架在会话/路由启动前按原内容直出，功能与视觉不受影响）。
# 以下 location 仅为性能优化（让 Nginx 直接读盘、绕过 PHP），可按需添加：
# location ~ ^/(css|js|uploads|vendor)/ {
#     root /www/wwwroot/你的站点/public;
#     try_files $uri =404;
# }

# 禁止直接访问敏感文件/目录
location ~ ^/(config\.php|runtime/|backups/) { deny all; }
```

不要把 `location /` 写成 `try_files $uri =404`，否则 `/post/...`、`/category/...` 等动态路径会在
进入 PHP 前直接返回 404。修改后执行 `nginx -t`，再重载 Nginx。

> **快速判断**：用同一篇已发布文章测试 `/post/文章slug` 和
> `/index.php?p=post/文章slug`。只有后者能打开时，就是伪静态配置未生效；两者都打不开再检查
> 文章状态、站点目录和 PHP 错误日志。项目入口也兼容 `/index.php/路径` 及子目录部署。

> **排查提示**：若页面能打开但 CSS/JS 加载不出来（样式全丢），是**旧版本（v0.1.1 及更早）**缺少静态兜底、且服务器未配上面的「静态资源」规则所致。**v0.1.2 起已内置 PHP 静态兜底**，只需伪静态一条 `try_files`，无需再配置静态 location（配了则 Nginx 直接读盘，性能更佳）。

> **主题资源 404**：如果 `/theme-assets/主题名/css/style.css` 的响应正文有内容但状态仍为 404，说明宝塔模板通过 `error_page 404` 转发入口并保留了状态码。请添加上面的 `location ^~ /theme-assets/`，或改用 `index.php?p=theme-assets/主题名/...` 验证；新版入口也会在找到文件时显式返回 200。

Apache（.htaccess）已随发布包内置（含静态资源重写），无需配置。
若服务器无法启用伪静态，可重新运行安装向导时取消勾选「启用伪静态」，或编辑 `config.php` 关闭 `pretty_urls`——该模式下链接自动使用 `index.php?p=xxx` 形式，**无需任何重写规则**。

## 七、安全建议

- 删除 `install.php`
- 立即修改默认管理员密码（admin / Admin@12345）
- 站点设置 → 安全：建议关闭注册（或开启邮箱验证）、开启评论审核
- 定期在后台「备份」页创建数据库备份
