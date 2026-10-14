<?php

declare(strict_types=1);

use Pafish\Services\ExtensionContext;

return static function (ExtensionContext $ctx): void {
    // 默认主题没有额外业务处理器，统一入口用于保持主题契约一致。
};
