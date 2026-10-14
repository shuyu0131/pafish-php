-- 旧共享设置只归入迁移时的当前主题；其他主题从自己的默认值开始。
-- 新空间已有的值优先，重复运行不会用旧值覆盖新配置。
INSERT IGNORE INTO settings (`key`, `value`)
SELECT CONCAT('theme_settings:',
    COALESCE(NULLIF((SELECT current_theme.`value` FROM settings AS current_theme WHERE current_theme.`key` = 'active_theme'), ''), 'default'),
    ':', SUBSTRING(legacy.`key`, 7)), legacy.`value`
FROM settings AS legacy
WHERE LEFT(legacy.`key`, 6) = 'theme:';

DELETE FROM settings WHERE LEFT(`key`, 6) = 'theme:';
