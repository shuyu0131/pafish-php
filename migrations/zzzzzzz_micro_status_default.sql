-- 新建微语默认直接发布；明确提交 status=DRAFT 仍保留草稿能力。
ALTER TABLE micro_statuses MODIFY COLUMN status VARCHAR(20) NOT NULL DEFAULT 'PUBLISHED' COMMENT '所属业务状态';
