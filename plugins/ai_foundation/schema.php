<?php

declare(strict_types=1);

/** Compatibility reader; plugin.json is the runtime schema source of truth. */
$manifest = json_decode((string) file_get_contents(__DIR__ . '/plugin.json'), true);
$schema = is_array($manifest) ? ($manifest['schema'] ?? null) : null;

return is_array($schema) ? $schema : ['tables' => []];
