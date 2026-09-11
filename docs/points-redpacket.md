# 积分与积分红包

## 积分:核心用户能力

积分账本是**核心层的通用用户能力**,与主题无关,任何主题均可使用:

| 模块 | 位置 |
| --- | --- |
| 账本服务 | `app/Services/Points.php` + `user_points` / `point_transactions` 表(迁移 `20260816_points_redpackets.sql`) |
| 个人中心 | `GET /profile` + `app/Views/theme/profile.php`(通用模板,包含积分流水) |
| 后台调整 | `POST /admin/users/{id}/points`(仅 ADMIN,保留流水) |

账本规则:整数账本,每次余额变动必写流水;带引用类型的调整幂等;行级锁防并发;负余额拒绝。未迁移站点自动隐藏积分区块。

## 积分红包主题扩展

红包的业务机制(建表、文章保存同步、领取 API)由核心承载，主题通过钩子提供界面。

- 文章通过 `custom_fields` 的 `redpacket_*` 键声明红包(键名为核心命名空间,不依赖任何主题的内容类型键):

```json
[
  { "key": "redpacket_total", "value": "1000" },
  { "key": "redpacket_count", "value": "20" },
  { "key": "redpacket_mode", "value": "random" },
  { "key": "redpacket_title", "value": "恭喜发财" }
]
```

- 核心行为:创建时冻结作者积分;编辑只允许改标题,其余参数不可变;领取一人一次、作者不能领自己的;永久删除文章时剩余积分自动退还(流水 `redpacket_refund`,幂等)。
- 领取入口:`POST /api/redpacket/{postId}/claim`(需登录 + CSRF)。
- 主题可在模板和前端脚本中调用红包状态接口实现展示。

## 安全要点

- 所有余额变动都必须经 `Points::adjust/adjustLocked`(禁止直接 UPDATE `user_points`)。
- 参考类型 `redpacket_create`(按 `post_id`)、`redpacket_claim`(按 `packet_id`)、`redpacket_refund`(按 `post_id`)保证各环节幂等。
- 领取与退款都在事务中 `FOR UPDATE` 锁红包行,并发安全。
