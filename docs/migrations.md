# 数据库迁移与升级回滚

> 自 v0.1.6 起,数据库结构演进使用版本化迁移(`app/Services/Migrator.php` + `migrations/*.sql`)。

## 目录结构

- `migrations/0001_initial.sql` —— 基线,与 `app/install/schema.sql` 结构一致(发布脚本自动校验,不一致拒绝打包)
- `migrations/20260816_points_redpackets.sql` … —— 增量迁移,文件名以日期前缀开头(格式 `YYYYMMDD_语义名.sql`,自然排序且不依赖序号),按文件名升序应用
- `schema_migrations` 表 —— 记录已应用的迁移版本(`version` 主键 + `applied_at`)

## 三条应用路径(殊途同归)

| 场景 | 路径 |
| --- | --- |
| 全新安装 | `install.php` 执行 `schema.sql`(宽松、已验证)→ 标记 `0001_initial` 已应用 |
| 存量库升级 | `Migrator::run()` 检测到已有 `users` 表且无迁移记录 → 自动把 `0001_initial` 视为已应用 → 只应用其后增量 |
| 手工导入 | 直接导入 `0001_initial.sql` 建库后,后续增量用 `Migrator::run()` 应用 |

任何时刻调用 `Migrator::run()` 都是幂等的:已应用的跳过,未应用的按序执行并记录。

## 新增迁移的步骤

1. 在 `migrations/` 下新建 `YYYYMMDD_语义名.sql`(名称取当天日期,如 `20260816_points_redpackets.sql`;版本号即文件名去 `.sql` 后缀)
2. 内容只包含 MySQL 语句(无存储过程;`--` 行注释会被忽略,分号拆分为独立语句执行)
3. **每个文件只放一个目的**:MySQL DDL 隐式提交,单个迁移内失败无法整体回滚;失败时该迁移不记录,修复后重跑即可
4. 如需在迁移前后跑 PHP 逻辑,随更新包放置 `upgrade.php`(在线更新通道执行后自动删除,见下)

## 在线更新的迁移与回滚

在线更新(`/admin/upgrade`)流程:

1. 下载更新包 → SHA-256 校验 → 结构校验(zip slip / 顶层目录 / 关键文件)
2. **整站备份**(排除 `runtime/ backups/ public/uploads/` 与 `config.php`)
3. 清空并解压新版本
4. 执行包内 `upgrade.php`(若存在)——未来版本在此调用 `Migrator::run()` 应用增量迁移
5. 任一步失败 → **自动回滚**:从备份整体恢复,站点回到旧版本

手动回滚:`runtime/.upgrade-bak-*/` 是升级失败时保留的备份;回滚失败时备份保留在 `runtime/`,按备份目录内结构手动覆盖回根目录即可。

## 建议的升级回滚验证流程(发版前)

1. 在测试环境安装上一版本 → 写入若干测试数据(文章/评论/设置)
2. 上传新版本更新包执行在线更新 → 确认 `schema_migrations` 新增记录、数据完好
3. 模拟迁移失败(如故意写坏一条 SQL)→ 确认自动回滚后站点可用、数据无丢失
4. 验证 `Migrator::run()` 幂等:重复执行不重复应用
