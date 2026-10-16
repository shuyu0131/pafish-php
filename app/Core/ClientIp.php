<?php

declare(strict_types=1);

namespace Pafish\Core;

use Psr\Http\Message\ServerRequestInterface as Request;

final class ClientIp
{
    /** Only accept forwarding headers from explicitly configured proxy IPs/CIDRs. */
    public static function fromRequest(Request $request): string
    {
        $peer = trim((string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''));
        if (filter_var($peer, FILTER_VALIDATE_IP) === false) return 'unknown';
        if (!self::trusted($peer)) return $peer;
        $forwarded = trim($request->getHeaderLine('X-Forwarded-For'));
        if ($forwarded === '') $forwarded = trim($request->getHeaderLine('X-Real-IP'));
        if ($forwarded === '' || strlen($forwarded) > 2048) return $peer;
        $chain = array_map('trim', explode(',', $forwarded));
        if (count($chain) > 32) return $peer;
        foreach ($chain as $ip) if (filter_var($ip, FILTER_VALIDATE_IP) === false) return $peer;
        $current = $peer;
        for ($i=count($chain)-1; $i>=0 && self::trusted($current); $i--) $current = $chain[$i];
        return $current;
    }

    private static function trusted(string $ip): bool
    {
        $binary = inet_pton($ip);
        foreach ((array) Config::get('trusted_proxies', []) as $network) {
            if (!is_string($network)) continue;
            $parts = explode('/', $network, 2);
            $base = @inet_pton($parts[0]);
            if ($base === false || strlen($base) !== strlen($binary)) continue;
            $bits = isset($parts[1]) && ctype_digit($parts[1]) ? (int) $parts[1] : (isset($parts[1]) ? -1 : strlen($base)*8);
            if ($bits < 0 || $bits > strlen($base)*8) continue;
            $bytes = intdiv($bits, 8);
            $rest = $bits % 8;
            if (substr($binary,0,$bytes) === substr($base,0,$bytes)
                && ($rest === 0 || (ord($binary[$bytes]) & (255 << (8-$rest))) === (ord($base[$bytes]) & (255 << (8-$rest))))) return true;
        }
        return false;
    }
}
