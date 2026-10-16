<?php

declare(strict_types=1);

namespace Pafish\Tests;

use Pafish\Core\Config;
use PDO;
use PDOStatement;

/** Real InnoDB tables with isolated names; never rewrites application data. */
final class FixtureStatement extends PDOStatement
{
    protected function __construct(private array $names, private \Closure $afterExecute) {}

    public function execute(?array $params = null): bool
    {
        if ($params !== null && stripos($this->queryString, 'INFORMATION_SCHEMA') !== false) {
            $params = array_map(fn ($value) => is_string($value) ? ($this->names[$value] ?? $value) : $value, $params);
        }
        $result = parent::execute($params);
        ($this->afterExecute)($this->queryString);
        return $result;
    }
}

final class MySql extends PDO
{
    private array $names = [];
    public ?\Closure $afterQuery = null;
    public ?\Closure $afterExecute = null;

    public function __construct(public readonly string $prefix)
    {
        if (preg_match('/^qa_core_[a-f0-9]{12}_$/D', $prefix) !== 1) {
            throw new \InvalidArgumentException('Invalid fixture prefix');
        }
        $config = Config::get('db');
        parent::__construct(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config['host'], $config['port'] ?? 3306, $config['database']), $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        preg_match_all('/CREATE TABLE IF NOT EXISTS (\w+)/', file_get_contents(PAFISH_ROOT . '/app/install/schema.sql'), $matches);
        foreach (array_merge($matches[1], ['schema_migrations', 'fixture_log', 'pf_plugin_qa_foundation_records', 'email_codes']) as $name) {
            $this->names[$name] = $prefix . $name;
        }
        // Backup discovers installed and disabled extensions. Never let its manifest names
        // fall through to the real application's private tables in a fixture connection.
        foreach (['plugin'=>'plugins', 'theme'=>'themes'] as $kind=>$directory) {
            foreach (glob(PAFISH_ROOT . '/' . $directory . '/*/' . $kind . '.json') ?: [] as $file) {
                $manifest = json_decode((string) file_get_contents($file), true);
                $name = basename(dirname($file));
                if (!is_array($manifest) || \Pafish\Services\ExtensionSchema::validateDeclaration($manifest['schema'] ?? null, $kind, $name) !== null) continue;
                foreach ($manifest['schema']['tables'] ?? [] as $table) {
                    $logical = \Pafish\Services\ExtensionSchema::physicalName($kind, $name, $table['name']);
                    // MySQL identifiers have a 64-character limit including fixture prefix.
                    $this->names[$logical] = $prefix . 'ext_' . substr(hash('sha256', $logical), 0, 24);
                }
            }
        }
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [FixtureStatement::class, [$this->names, function (string $sql): void {
            if ($this->afterExecute !== null) ($this->afterExecute)($sql);
        }]]);
        $this->query("SET time_zone = '+08:00'");
    }

    private function rewrite(string $sql): string
    {
        $sql = preg_replace_callback('/\bCONSTRAINT\s+`?(\w+)`?/i',
            fn ($match) => 'CONSTRAINT `' . $this->prefix . $match[1] . '`', $sql);
        // Quoted values stay unchanged; unquoted/backtick-quoted identifiers are mapped.
        return preg_replace_callback('/\'(?:\'\'|\\\\.|[^\'\\\\])*\'|"(?:""|\\\\.|[^"\\\\])*"|`([^`]+)`|\b([A-Za-z_][A-Za-z0-9_]*)\b/',
            function ($match) {
                if (($match[1] ?? '') !== '') {
                    return '`' . ($this->names[$match[1]] ?? $match[1]) . '`';
                }
                return isset($match[2]) ? ($this->names[$match[2]] ?? $match[2]) : $match[0];
            }, $sql);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $original = $query;
        $query = $this->rewrite($query);
        $result = $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
        if ($this->afterQuery !== null) ($this->afterQuery)($original);
        return $result;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare($this->rewrite($query), $options);
    }

    public function cleanup(): void
    {
        if ($this->inTransaction()) {
            $this->rollBack();
        }
        parent::query('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($this->names as $physical) {
                if (!str_starts_with($physical, $this->prefix) || preg_match('/^[a-z0-9_]+$/D', $physical) !== 1) {
                    throw new \RuntimeException('Refusing to clean an application table');
                }
                parent::query('DROP TABLE IF EXISTS `' . $physical . '`');
            }
        } finally {
            parent::query('SET FOREIGN_KEY_CHECKS = 1');
        }
    }
}
