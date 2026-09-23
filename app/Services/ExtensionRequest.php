<?php

declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\Session;
use Pafish\Core\Url;
use Psr\Http\Message\ServerRequestInterface;

/** 提供给扩展路由 handler 的受控请求上下文。 */
final class ExtensionRequest
{
    public function __construct(
        private readonly ServerRequestInterface $request,
        private readonly array $params,
        private readonly ?array $user,
        private readonly string $kind,
        private readonly string $name,
    ) {
    }

    public function method(): string { return strtoupper($this->request->getMethod()); }
    public function query(): array { return $this->request->getQueryParams(); }
    public function body(): array { return is_array($this->request->getParsedBody()) ? $this->request->getParsedBody() : []; }
    public function input(string $key, mixed $default = null): mixed { return $this->body()[$key] ?? $this->query()[$key] ?? $default; }
    public function queryString(string $key, string $default = ''): string { return self::stringValue($this->query()[$key] ?? null, $default); }
    public function queryInt(string $key, int $default = 0): int { return self::intValue($this->query()[$key] ?? null, $default); }
    public function queryBool(string $key, bool $default = false): bool { return self::boolValue($this->query()[$key] ?? null, $default); }
    public function queryArray(string $key, array $default = []): array { return self::arrayValue($this->query()[$key] ?? null, $default); }
    public function bodyString(string $key, string $default = ''): string { return self::stringValue($this->body()[$key] ?? null, $default); }
    public function bodyInt(string $key, int $default = 0): int { return self::intValue($this->body()[$key] ?? null, $default); }
    public function bodyBool(string $key, bool $default = false): bool { return self::boolValue($this->body()[$key] ?? null, $default); }
    public function bodyArray(string $key, array $default = []): array { return self::arrayValue($this->body()[$key] ?? null, $default); }
    public function inputString(string $key, string $default = ''): string { return self::stringValue($this->input($key), $default); }
    public function inputInt(string $key, int $default = 0): int { return self::intValue($this->input($key), $default); }
    public function inputBool(string $key, bool $default = false): bool { return self::boolValue($this->input($key), $default); }
    public function inputArray(string $key, array $default = []): array { return self::arrayValue($this->input($key), $default); }
    public function param(string $key, string $default = ''): string { return isset($this->params[$key]) ? (string) $this->params[$key] : $default; }
    public function params(): array { return $this->params; }
    public function user(): ?array { return $this->user; }
    public function uploads(): array { return $this->request->getUploadedFiles(); }
    public function csrfToken(): string { return Session::csrfToken(); }
    public function url(string $path): string { return Url::to($path); }
    public function extensionKind(): string { return $this->kind; }
    public function extensionName(): string { return $this->name; }

    private static function stringValue(mixed $value, string $default): string
    {
        return is_string($value) || is_numeric($value) ? (string) $value : $default;
    }

    private static function intValue(mixed $value, int $default): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value)) {
            return $default;
        }
        $value = trim($value);
        if (preg_match('/^([+-]?)(\d+)$/D', $value, $matches) !== 1) {
            return $default;
        }
        $digits = ltrim($matches[2], '0');
        $digits = $digits === '' ? '0' : $digits;
        $normalized = ($matches[1] === '-' && $digits !== '0' ? '-' : '') . $digits;
        $validated = filter_var($normalized, FILTER_VALIDATE_INT);
        return $validated === false ? $default : $validated;
    }

    private static function boolValue(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return match ($value) {
                1 => true,
                0 => false,
                default => $default,
            };
        }
        if (!is_string($value)) {
            return $default;
        }
        return match (strtolower(trim($value))) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => $default,
        };
    }

    private static function arrayValue(mixed $value, array $default): array
    {
        return is_array($value) ? $value : $default;
    }
}
