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

宝塔建站默认已配置 `try_files`，一般无需额外操作。若站点使用了其他 Nginx 配置，确认包含：

```nginx
# 伪静态：所有前台路径交给 index.php
location / {
    try_files $uri $uri/ /index.php?$query_string;
}

# 静态资源在 public/ 下，对外保持根路径（css/ js/ uploads/）：
# 请求 /css/style.css → 站点根/public/css/style.css
# 若忽略本规则，资源将由 PHP 兜底服务（稍慢），功能不受影响
location ~ ^/(css|js|uploads)/ {
    root /www/wwwroot/你的站点/public;
    try_files $uri =404;
}

# 禁止直接访问敏感文件/目录
location ~ ^/(config\.php|runtime/|backups/) { deny all; }
```

> **排查提示**：若页面能打开但 CSS/JS 加载不出来（样式全丢），通常是缺少上面的「静态资源」规则、且使用了未含兜底逻辑的旧版本。v0.1.1 起内置 PHP 静态兜底（即便没有该规则 CSS 也会由 PHP 返回，只是稍慢），建议仍按上表配置以获得最佳性能。

Apache（.htaccess）已随发布包内置（含静态资源重写），无需配置。
若服务器无法启用伪静态，可重新运行安装向导时取消勾选「启用伪静态」，或编辑 `config.php` 关闭 `pretty_urls`——该模式下链接自动使用 `index.php?p=xxx` 形式，**无需任何重写规则**。

## 七、安全建议

- 删除 `install.php`
- 立即修改默认管理员密码（admin / Admin@12345）
- 站点设置 → 安全：建议关闭注册（或开启邮箱验证）、开启评论审核
- 定期在后台「备份」页创建数据库备份
