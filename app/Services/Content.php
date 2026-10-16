<?php

declare(strict_types=1);

namespace Pafish\Services;

/** 持久化的正文格式属于核心；停用编辑器插件不影响阅读。 */
final class Content
{
    private static ?\HTMLPurifier $purifier = null;

    /** Empty values opt into generated metadata; omitted keys preserve an existing value. */
    public static function seoFields(array $data, array $existing = []): array
    {
        $result = [];
        foreach (['seo_title' => 255, 'meta_description' => 500] as $key => $limit) {
            $value = $data[$key] ?? $existing[$key] ?? '';
            if (!is_string($value)) throw new \RuntimeException('SEO 标题和描述必须是文本');
            $value = trim($value);
            if (mb_strlen($value) > $limit) throw new \RuntimeException('SEO 标题或描述超出长度限制');
            $result[$key] = $value;
        }
        return $result;
    }

    /** Image addresses are stored verbatim; no remote fetching is performed here. */
    public static function imageUrl(mixed $value): ?string
    {
        if (!is_string($value)) throw new \RuntimeException('图片地址必须是文本');
        $value = trim($value);
        if ($value === '') return null;
        if (mb_strlen($value) > 500 || preg_match('/[\x00-\x20\x7f\\\\]/', $value)) {
            throw new \RuntimeException('图片地址无效');
        }
        if (\Pafish\Core\Url::isLocalPath($value)) return $value;
        $parts = parse_url($value);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http','https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new \RuntimeException('图片地址仅支持站内路径或 HTTP/HTTPS 地址');
        }
        return $value;
    }

    public static function format(mixed $format): string
    {
        if (!is_string($format) || !in_array($format, ['markdown', 'html'], true)) {
            throw new \InvalidArgumentException('正文格式无效');
        }
        return $format;
    }

    public static function sanitize(string $html): string
    {
        if (self::$purifier === null) {
            $config = \HTMLPurifier_Config::createDefault();
            $config->set('Core.Encoding', 'UTF-8');
            $config->set('Cache.DefinitionImpl', null);
            $config->set('HTML.Allowed', 'p[style],br,h1[style],h2[style],h3[style],h4[style],h5,h6,strong,b,em,i,u,s,del,sub,sup,span[style],blockquote,ul,ol[start],li,pre,code[class],a[href|title|target|rel],img[src|alt|title|width|height],table,thead,tbody,tfoot,tr,th[colspan|rowspan|scope|style],td[colspan|rowspan|style],hr');
            $config->set('CSS.AllowedProperties', ['color', 'background-color', 'text-align', 'text-decoration', 'font-size', 'font-weight']);
            $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true, 'tel' => true]);
            $config->set('Attr.AllowedFrameTargets', ['_blank']);
            $config->set('HTML.TargetBlank', true);
            self::$purifier = new \HTMLPurifier($config);
        }
        return self::$purifier->purify($html);
    }

    public static function render(string $content, string $format = 'markdown'): string
    {
        if (self::format($format) === 'markdown') {
            return Markdown::render($content);
        }
        $html = self::sanitize($content);
        $html = preg_replace('/<table\b/i', '<div class="md-table-wrap"><table', $html);
        return (string) str_replace('</table>', '</table></div>', (string) $html);
    }

    public static function plain(string $content, string $format = 'markdown'): string
    {
        $html = self::render($content, $format);
        $html = (string) preg_replace('#</(?:p|h[1-6]|li|tr|blockquote|pre)>|<br\s*/?>#i', ' ', $html);
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
