<?php

declare(strict_types=1);

namespace Pafish\Services;

/** Server-side comment honeypot, email denylist and plain-text keyword rules. */
final class CommentSpamProtection
{
    public static function honeypotTriggered(array $body): bool
    {
        $value = $body['website'] ?? '';
        return is_scalar($value) && trim((string) $value) !== '';
    }

    public static function isEmailBlocked(string $email): bool
    {
        $email = strtolower(trim($email));
        if ($email === '') return false;
        foreach (self::settingList('blocked_emails') as $entry) {
            $entry = strtolower(trim($entry));
            if ($entry === $email) return true;
            if (str_starts_with($entry, '@') && str_ends_with($email, $entry)) return true;
        }
        return false;
    }

    public static function matchingKeyword(string $content): ?string
    {
        $content = mb_strtolower($content, 'UTF-8');
        foreach (self::settingList('comment_spam_keywords') as $keyword) {
            $keyword = trim($keyword);
            if ($keyword !== '' && mb_stripos($content, mb_strtolower($keyword, 'UTF-8'), 0, 'UTF-8') !== false) {
                return $keyword;
            }
        }
        return null;
    }

    private static function settingList(string $key): array
    {
        try {
            $value = json_decode((string) Settings::get($key, '[]'), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }
        if (!is_array($value)) return [];
        return array_values(array_filter($value, 'is_string'));
    }
}
