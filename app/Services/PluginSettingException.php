<?php
declare(strict_types=1);

namespace Pafish\Services;

/**
 * 插件设置校验失败：携带**逐字段**错误，供保存接口回传。
 *
 * 主题侧的 AppearanceController 直接收集 $errors 数组返回 400；
 * 插件侧保存逻辑在 Service 里，需要一个能带字段明细的异常，
 * 否则前端只能拿到一句合并后的错误，无法把红框标到具体字段上。
 */
class PluginSettingException extends \RuntimeException
{
    /** @var array<string, string> key => 错误信息 */
    private array $fieldErrors;

    /** @param array<string, string> $fieldErrors */
    public function __construct(array $fieldErrors)
    {
        $this->fieldErrors = $fieldErrors;
        parent::__construct(implode('；', array_values($fieldErrors)));
    }

    /** @return array<string, string> */
    public function fieldErrors(): array
    {
        return $this->fieldErrors;
    }
}
