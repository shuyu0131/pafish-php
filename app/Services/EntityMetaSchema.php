<?php

declare(strict_types=1);

namespace Pafish\Services;

/** Validate extension-owned entity fields declared in a plugin or theme manifest. */
final class EntityMetaSchema
{
    private const SUBJECTS = ['post', 'page', 'user', 'comment', 'micro', 'category', 'tag'];
    private const TYPES = ['text', 'textarea', 'number', 'checkbox', 'select'];

    public static function validate(mixed $schema): ?string
    {
        if ($schema === null) return null;
        if (!is_array($schema)) return 'meta 必须是按实体类型分组的对象';
        if (array_diff(array_keys($schema), self::SUBJECTS) !== []) return 'meta 包含无效实体类型';
        $total = 0;
        foreach ($schema as $subject => $fields) {
            if (!is_array($fields) || !array_is_list($fields)) return "meta.{$subject} 必须是字段数组";
            $seen = [];
            foreach ($fields as $field) {
                if (!is_array($field)) return "meta.{$subject} 包含无效字段";
                $key = $field['key'] ?? null;
                $label = $field['label'] ?? null;
                $type = $field['type'] ?? 'text';
                if (!is_string($key) || preg_match('/^[a-zA-Z0-9_.-]{1,80}$/D', $key) !== 1
                    || isset($seen[$key]) || !is_string($label) || trim($label) === '' || mb_strlen($label) > 80
                    || !is_string($type) || !in_array($type, self::TYPES, true)) {
                    return "meta.{$subject} 字段的 key、label 或 type 无效";
                }
                $seen[$key] = true;
                if (array_key_exists('required', $field) && !is_bool($field['required'])) return "meta.{$subject}.{$key}.required 必须是布尔值";
                if (array_key_exists('desc', $field) && (!is_string($field['desc']) || mb_strlen($field['desc']) > 300)) {
                    return "meta.{$subject}.{$key}.desc 必须是最多 300 字的字符串";
                }
                if (array_key_exists('maxLength', $field) && (!is_int($field['maxLength']) || $field['maxLength'] < 1 || $field['maxLength'] > 10000)) {
                    return "meta.{$subject}.{$key}.maxLength 必须在 1 到 10000 之间";
                }
                if ($type === 'select') {
                    $options = $field['options'] ?? null;
                    if (!is_array($options) || $options === [] || count($options) > 50) return "meta.{$subject}.{$key}.options 无效";
                    foreach ($options as $value => $optionLabel) {
                        if ((!is_string($value) && !is_int($value)) || (string) $value === ''
                            || !is_string($optionLabel) || trim($optionLabel) === '' || mb_strlen($optionLabel) > 100) {
                            return "meta.{$subject}.{$key}.options 包含无效选项";
                        }
                    }
                } elseif (array_key_exists('options', $field)) {
                    return "meta.{$subject}.{$key}.options 只适用于 select";
                }
                if (array_key_exists('default', $field) && !is_scalar($field['default'])) return "meta.{$subject}.{$key}.default 必须是标量";
                if ($type === 'checkbox' && array_key_exists('default', $field)
                    && !in_array($field['default'], [0, 1, '0', '1', false, true], true)) {
                    return "meta.{$subject}.{$key}.default 必须是 0 或 1";
                }
                if ($type === 'number' && isset($field['default']) && (string) $field['default'] !== ''
                    && !is_numeric($field['default'])) {
                    return "meta.{$subject}.{$key}.default 必须是数字";
                }
                if ($type === 'select') {
                    $options = array_map('strval', $field['options']);
                    $default = (string) ($field['default'] ?? array_key_first($field['options']));
                    if (!array_key_exists($default, $options)) return "meta.{$subject}.{$key}.default 必须对应一个选项";
                }
                if (isset($field['default']) && array_key_exists('maxLength', $field) && mb_strlen((string) $field['default']) > $field['maxLength']) {
                    return "meta.{$subject}.{$key}.default 超出 maxLength";
                }
                if (++$total > 100) return '单个扩展最多声明 100 个实体字段';
            }
        }
        return null;
    }

    /** @return array<string, list<array<string, mixed>>> */
    public static function normalize(mixed $schema): array
    {
        if (self::validate($schema) !== null || !is_array($schema)) return [];
        $out = [];
        foreach ($schema as $subject => $fields) {
            foreach ($fields as $field) {
                $normalized = [
                    'key' => $field['key'],
                    'label' => trim($field['label']),
                    'type' => $field['type'] ?? 'text',
                    'required' => $field['required'] ?? false,
                    'default' => ($field['type'] ?? '') === 'checkbox'
                        ? ((bool) ($field['default'] ?? false) ? '1' : '0')
                        : (string) ($field['default'] ?? ''),
                ];
                if (isset($field['maxLength'])) $normalized['maxLength'] = $field['maxLength'];
                if (isset($field['desc']) && trim((string) $field['desc']) !== '') $normalized['desc'] = trim((string) $field['desc']);
                if (($normalized['type'] ?? '') === 'select') {
                    $normalized['options'] = array_map('strval', $field['options']);
                    $normalized['default'] = (string) ($field['default'] ?? array_key_first($field['options']));
                }
                $out[$subject][] = $normalized;
            }
        }
        return $out;
    }

    public static function validateValue(array $field, mixed $raw): string
    {
        if (!is_scalar($raw) && $raw !== null) throw new \RuntimeException('字段「' . $field['label'] . '」值无效');
        $value = (string) ($raw ?? '');
        $type = (string) ($field['type'] ?? 'text');
        if ($type === 'checkbox') {
            if (!in_array($value, ['0', '1'], true)) throw new \RuntimeException('字段「' . $field['label'] . '」值无效');
        } elseif ($type === 'number' && $value !== '' && !is_numeric($value)) {
            throw new \RuntimeException('字段「' . $field['label'] . '」必须是数字');
        } elseif ($type === 'select' && !array_key_exists($value, (array) ($field['options'] ?? []))) {
            throw new \RuntimeException('字段「' . $field['label'] . '」选项无效');
        }
        if (($field['required'] ?? false) && trim($value) === '') throw new \RuntimeException('字段「' . $field['label'] . '」不能为空');
        $maxLength = (int) ($field['maxLength'] ?? 10000);
        if (mb_strlen($value) > $maxLength) throw new \RuntimeException('字段「' . $field['label'] . '」不能超过 ' . $maxLength . ' 个字符');
        return $value;
    }
}
