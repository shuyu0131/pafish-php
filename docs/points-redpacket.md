# 积分与积分红包(通用能力)

> 积分账本与积分红包是**核心层的通用用户能力**,不属于任何主题:lumina 只是第一个实现其界面呈现的主题。其它主题(或插件)可以完全复用,不会受到 lumina 影响。

## 能力边界

| 模块 | 位置 | 说明 |
| --- | --- | --- |
| 积分账本 | `app/Services/Points.php` + `user_points` / `point_transactions` 表(迁移 `0002_points_redpackets.sql`) | 整数账本:每次余额变动必写流水;带引用类型的调整幂等;行级锁防并发;负余额拒绝 |
| 红包服务 | `app/Services/RedPacket.php` + `redpackets` / `redpacket_claims` 表 | 创建即冻结作者积分;领取行锁、一人一次、作者不能领自己的;永久删除文章时剩余积分自动退还 |
| 领取 API | `POST /api/redpacket/{postId}/claim`(`app/Http/RedPacketController.php`) | 需登录 + CSRF;返回 `{ok, amount, remaining_count, remaining_points}` 或 `{error}` |
| 个人中心 | `GET /profile`(`app/Http/ProfileController.php` + `app/Views/theme/profile.php`) | 通用模板:资料/改密/积分流水/红包领取记录;未迁移站点自动隐藏积分区块 |
| 后台积分调整 | `POST /admin/users/{id}/points`(仅 ADMIN) | 管理员发放/扣减积分,保留流水 |

## 文章里怎么声明红包(内容格式)

红包通过文章的 `custom_fields` 声明,键名属于 lumina 内容格式命名空间(lumina 媒体面板的既有约定):

```json
[
  { "key": "lumina_type", "value": "redpacket" },
  { "key": "lumina_redpacket_total", "value": "1000" },
  { "key": "lumina_redpacket_count", "value": "20" },
  { "key": "lumina_redpacket_mode", "value": "random" },
  { "key": "lumina_redpacket_title", "value": "恭喜发财" }
]
```

- 文章保存时核心自动校验并落库(创建冻结 `total`,编辑只允许改标题,其余参数不可变)。
- 删除文章到回收站:红包保持冻结(文章不可见,不可领);恢复文章可继续领取。
- 永久删除:剩余积分退还给作者(流水 `redpacket_refund`,幂等)。
- 表不存在(未迁移)时所有能力自动降级隐藏,不影响站点其它功能。

## 其他主题如何复现界面

主题只需在详情页渲染领取卡片并调用领取 API:

1. 取红包状态:`\Pafish\Services\RedPacket::state($postId, $viewerId)`(返回 `status`/`remaining_*`/`claimed` 等,`UNAVAILABLE` 表示未迁移)。
2. 渲染按钮,携带 `csrf_token()` 与 `$postId`;点击后 `POST /api/redpacket/{postId}/claim`,`_csrf` 放表单体里。
3. 401 时引导去登录,其余错误码展示服务返回的 `error` 文案。

参考实现:`themes/lumina/helpers.php`(`lumina_render_media` 的 `redpacket` 分支)+ `themes/lumina/assets/lumina.js` 的领取交互。

个人中心:其它主题若不提供自己的 `profile.php`,`app/Views/theme/profile.php` 直接可用;若想隐藏积分区块,提供同名模板覆盖即可。

## 安全与一致性要点

- 所有余额变动都必须经 `Points::adjust/adjustLocked`(禁止直接 UPDATE `user_points`)。
- 参考类型 `redpacket_create`(按 `post_id`)、`redpacket_claim`(按 `packet_id`)、`redpacket_refund`(按 `post_id`)保证各环节幂等。
- 领取与退款都在事务中 `FOR UPDATE` 锁红包行,并发安全。