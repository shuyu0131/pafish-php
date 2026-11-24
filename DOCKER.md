# Docker 部署

仓库提供 Apache/PHP 应用、MySQL 8.4 和定时任务的 Compose 配置。网站代码在镜像中，数据库与可写数据通过命名卷保存。本文用于部署网站；不需要先发布自己的 Docker 镜像。

## 选择启动方式

需要 Docker Engine 与 Compose v2。命令在放有 docker-compose.yml 的项目目录执行。

| 情况 | 使用方式 |
| --- | --- |
| 已取得可拉取的项目发行镜像 | 默认 Compose，PAFISH_IMAGE 指向实际发行标签 |
| 只有本地源码，或要验证当前修改 | 同时使用 docker-compose.build.yml 本地构建 |

默认镜像地址是 ghcr.io/shuyu0131/pafish-php:latest。仓库写了地址不代表该标签一定已公开；拉取失败时先检查标签和访问权限，或使用本地构建。正式环境应固定到实际存在的版本标签。

## 准备环境变量

复制 .env.example 为 .env，填写两个不同的长随机数据库密码。Linux/macOS 示例：

```bash
cp .env.example .env
```

Windows PowerShell 可使用 Copy-Item .env.example .env。不要把 .env 提交到仓库或公开下载目录。

| 变量 | 说明 |
| --- | --- |
| MYSQL_DATABASE、MYSQL_USER | 初次初始化 MySQL 的库名与普通账户 |
| MYSQL_PASSWORD | 普通数据库账户密码 |
| MYSQL_ROOT_PASSWORD | 与普通账户不同的 MySQL root 密码 |
| PAFISH_IMAGE | 实际应用镜像标签，默认 GHCR latest |
| HTTP_BIND | 默认 127.0.0.1，只对宿主机开放 |
| HTTP_PORT | 宿主机端口，默认 8080 |

已有数据库卷不会因为改 .env 就重新初始化账户；应用安装后的连接也保存在 runtime/config.php。改密码需协调数据库账户、运行配置和后续初始化变量，不是重启一次就自动同步。

## 启动

使用发行镜像：

```bash
docker compose up -d
```

使用当前源码构建：

```bash
docker compose -f docker-compose.yml -f docker-compose.build.yml up -d --build
```

访问 http://localhost:8080/install.php。Compose 为安装器提供数据库连接默认值，填写最终公开站点 URL 和管理员信息完成向导。已有配置时不会再次安装。

当前镜像未提供专门的安装入口删除开关；安装器依靠已存在配置阻止重装。公开部署可在前置代理阻止后续 /install.php 访问，避免把普通主机的“删镜像内文件”当成可持久的镜像修改。

查看状态和日志：

```bash
docker compose ps
docker compose logs --tail=100 app scheduler db
```

scheduler 每分钟运行 cron.php，输出 0 篇到期文章是正常结果。健康检查只说明对应服务探测通过，不代表安装、登录、数据库迁移和所有扩展都已验证。

## 域名与反向代理

默认监听宿主机 127.0.0.1:8080，适合由宿主机反向代理转发。需要其他机器直接访问才把 HTTP_BIND 改为 0.0.0.0，并按实际防火墙策略开放端口。

代理转发正确的 Host 和协议，site_url 填读者使用的 HTTPS 地址。只把真实代理 IP/CIDR 放入 trusted_proxies。代理位于其他容器时，使用共同 Docker 网络访问 app:8080，不能用该代理容器自己的 127.0.0.1 指代应用。

Apache 已启用 mod_rewrite 和项目 .htaccess，正常可保留 pretty_urls。不要给容器 Apache 粘贴 Nginx location 规则。

## 持久卷保存什么

| 卷 | 内容 |
| --- | --- |
| pafish_mysql | MySQL 数据文件 |
| pafish_runtime | 运行配置、日志与缓存 |
| pafish_uploads | 本地上传 |
| pafish_backups | 数据库备份文件 |
| pafish_storage | 分片上传等运行文件 |
| pafish_plugins、pafish_themes | 已安装扩展代码 |

默认 Compose 项目名为 pafish；更改项目名或目录部署方式可能创建另一组卷，让网站看起来像未安装。先检查实际卷和项目归属，不立即重新初始化。

普通 docker compose down 不删除命名卷。down -v 会删除持久数据，只在确实准备丢弃站点时使用。卷仍在不等于已有异地备份，范围见 [备份与搬迁](docs/backup-migration.md)。

## 更新与内置扩展同步

发行镜像更新：

```bash
docker compose pull
docker compose up -d
```

源码构建则重复前面的双 Compose --build 命令。app 与 scheduler 应使用同一应用版本。核心文件由镜像提供，不用后台在线更新去修改容器里的核心代码。

当前 docker/entrypoint.sh 对 default、sitemap、ai_foundation 执行种子同步：目录缺少清单时补入，镜像清单版本更高时替换对应目录，版本相同或已安装更高版本时保留。其他主题插件目录保留在卷中。

这与普通核心在线更新“保留现有默认主题目录”的规则不同。自定义默认主题文件仍可能被更高版本镜像种子替换，应放进独立子主题。demo 仅在文档示例目录提供，不随 Docker 镜像分发；需要时手动复制到插件目录或单独安装。

## 故障和恢复

拉取不到镜像先检查 PAFISH_IMAGE；数据库启动失败先看 db 日志和卷状态；站点显示未安装先检查项目名与 runtime 卷；更新后业务异常按兼容版本和备份恢复，不盲目删除全部卷。

数据库版本升级需要兼容迁移，旧 MySQL 镜像不一定能读取新版本写入的数据卷。回退应用标签不等于数据库可原地降级。

## 镜像维护者

Dockerfile 与 Compose build override 用于构建，仓库发布工作流用于发布镜像。推送到 GHCR 或 Docker Hub 需要相应仓库权限；部署文档不代表镜像已经公开。项目自有镜像也不等于 Docker library/ 的 Official Image。镜像发布是独立维护流程，不是安装前置步骤。

[安装说明](docs/install.md) · [更新与维护](docs/maintenance.md) · [文档目录](docs/README.md)。
