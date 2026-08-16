<?php

declare(strict_types=1);

// 仅由在线升级器在新代码覆盖后执行；成功或失败都会由升级器删除/回滚该文件。
\Pafish\Services\Migrator::run(\Pafish\Core\DB::pdo(), PAFISH_ROOT . '/migrations');
