<?php

declare(strict_types=1);

namespace Pafish\Core;

/** Built-in UI translation. Catalogues are shipped with the application, never user paths. */
final class I18n
{
    public const DEFAULT_LOCALE = 'zh-CN';
    private const LOCALES = ['zh-CN' => '简体中文', 'en' => 'English'];
    private static string $locale = self::DEFAULT_LOCALE;
    private static array $catalogues = [];

    public static function locales(): array
    {
        return self::LOCALES;
    }

    public static function supports(string $locale): bool
    {
        return isset(self::LOCALES[$locale]);
    }

    public static function setLocale(string $locale): void
    {
        self::$locale = self::supports($locale) ? $locale : self::DEFAULT_LOCALE;
    }

    public static function locale(): string
    {
        return self::$locale;
    }

    public static function translate(string $key, array $parameters = []): string
    {
        $messages = self::catalogue(self::$locale);
        $fallback = self::catalogue(self::DEFAULT_LOCALE);
        $message = $messages[$key] ?? $fallback[$key] ?? $key;
        $replace = [];
        foreach ($parameters as $name => $value) {
            if (is_scalar($value) || $value === null || $value instanceof \Stringable) {
                $replace['{' . $name . '}'] = (string) $value;
            }
        }
        return $replace === [] ? $message : strtr($message, $replace);
    }

    /** Only browser messages are sent to the browser, with Chinese fallback already merged. */
    public static function browserMessages(): array
    {
        return array_filter(
            array_replace(self::catalogue(self::DEFAULT_LOCALE), self::catalogue(self::$locale)),
            static fn (string $key): bool => str_starts_with($key, 'js.') || str_starts_with($key, 'common.'),
            ARRAY_FILTER_USE_KEY
        );
    }

    private static function catalogue(string $locale): array
    {
        if (isset(self::$catalogues[$locale])) return self::$catalogues[$locale];
        // The only caller-supplied locale is normalized against LOCALES before reaching here.
        if (!self::supports($locale)) return [];
        $file = dirname(__DIR__) . '/Languages/' . $locale . '.json';
        try {
            $raw = is_file($file) ? file_get_contents($file) : false;
            $messages = $raw === false ? [] : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $messages = [];
        }
        return self::$catalogues[$locale] = is_array($messages)
            ? array_filter($messages, 'is_string')
            : [];
    }
}
