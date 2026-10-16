<?php

declare(strict_types=1);

namespace Pafish\Services;

use Pafish\Core\DB;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Per-user, per-device bearer credentials for first-party native clients. */
final class AppTokens
{
    private const LIFETIME_DAYS = 90;
    private const MAX_ACTIVE_PER_USER = 10;

    /** @return array{id:int,token:string,expiresAt:string} */
    public static function issue(int $userId, string $deviceName): array
    {
        $deviceName = trim(preg_replace('/[\x00-\x1f\x7f]/u', '', $deviceName) ?? '');
        if ($deviceName === '') $deviceName = '未命名设备';
        $deviceName = mb_substr($deviceName, 0, 100);

        $token = bin2hex(random_bytes(32));
        $issued = DB::transaction(static function () use ($userId, $deviceName, $token): array {
            if (DB::fetchOne('SELECT id FROM users WHERE id = ? AND disabled = 0 FOR UPDATE', [$userId]) === null) {
                throw new \RuntimeException('账号不可用');
            }
            $active = (int) DB::value('SELECT COUNT(*) FROM app_tokens WHERE user_id = ? AND revoked_at IS NULL AND expires_at > NOW()', [$userId]);
            if ($active >= self::MAX_ACTIVE_PER_USER) {
                throw new \RuntimeException('设备授权已达到上限，请先撤销不再使用的设备');
            }
            DB::execute('INSERT INTO app_tokens (user_id, token_hash, device_name, expires_at)
                VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ' . self::LIFETIME_DAYS . ' DAY))',
                [$userId, hash('sha256', $token), $deviceName]);
            $id = DB::lastInsertId();
            $expiresAt = (string) DB::value('SELECT expires_at FROM app_tokens WHERE id = ?', [$id]);
            return ['id' => $id, 'expiresAt' => $expiresAt];
        });

        return ['id' => $issued['id'], 'token' => $token, 'expiresAt' => $issued['expiresAt']];
    }

    /** @return array{tokenId:int,user:array}|null */
    public static function authenticate(Request $request): ?array
    {
        $header = trim($request->getHeaderLine('Authorization'));
        if (preg_match('/^Bearer ([a-f0-9]{64})$/iD', $header, $match) !== 1) return null;

        $hash = hash('sha256', strtolower($match[1]));
        $row = DB::fetchOne('SELECT t.id AS token_id, t.user_id, t.last_used_at,
                u.username, u.nickname, u.email, u.role, u.avatar_url
            FROM app_tokens t
            INNER JOIN users u ON u.id = t.user_id
            WHERE t.token_hash = ? AND t.revoked_at IS NULL AND t.expires_at > NOW() AND u.disabled = 0', [$hash]);
        if ($row === null) return null;

        DB::execute('UPDATE app_tokens SET last_used_at = NOW()
            WHERE id = ? AND (last_used_at IS NULL OR last_used_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE))', [(int) $row['token_id']]);

        return [
            'tokenId' => (int) $row['token_id'],
            'user' => [
                'id' => (int) $row['user_id'],
                'username' => (string) $row['username'],
                'nickname' => (string) ($row['nickname'] ?? ''),
                'email' => (string) $row['email'],
                'role' => (string) $row['role'],
                'avatarUrl' => $row['avatar_url'],
            ],
        ];
    }

    public static function revoke(int $userId, int $tokenId): bool
    {
        return DB::execute('UPDATE app_tokens SET revoked_at = NOW()
            WHERE id = ? AND user_id = ? AND revoked_at IS NULL', [$tokenId, $userId]) > 0;
    }

    public static function revokeAll(int $userId): int
    {
        return DB::execute('UPDATE app_tokens SET revoked_at = NOW()
            WHERE user_id = ? AND revoked_at IS NULL', [$userId]);
    }

    public static function listForUser(int $userId, int $limit = 50): array
    {
        $limit = max(1, min(50, $limit));
        return DB::fetchAll('SELECT id, device_name, created_at, expires_at, last_used_at, revoked_at
            FROM app_tokens WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT ' . $limit, [$userId]);
    }
}
