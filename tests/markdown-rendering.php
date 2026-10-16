<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('PAFISH_ROOT', dirname(__DIR__));
require PAFISH_ROOT . '/vendor/autoload.php';
require __DIR__ . '/Fixtures/MySql.php';
require PAFISH_ROOT . '/app/Core/helpers.php';

use Pafish\Core\{Config, DB, SqlScript};
use Pafish\Services\{Content, Markdown, Settings};
use Pafish\Tests\MySql;

Config::load(Config::resolveFile(PAFISH_ROOT));
$pdo = new MySql('qa_core_' . bin2hex(random_bytes(6)) . '_');
$connection = new ReflectionProperty(DB::class, 'pdo');
$connection->setValue(null, $pdo);
$checks = 0;
$check = static function (string $label, bool $ok) use (&$checks): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $label);
    ++$checks; echo 'PASS ' . $label . PHP_EOL;
};
$document = static function (string $html): DOMXPath {
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    try { $dom->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>'); }
    finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
    return new DOMXPath($dom);
};
try {
    foreach (SqlScript::split(file_get_contents(PAFISH_ROOT . '/app/install/schema.sql')) as $statement) $pdo->query($statement);
    Settings::reset();
    Settings::set('md_allow_raw_html', '0');
    set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    $check('Existing soft line break semantics remain unchanged', !str_contains(Markdown::render("第一行\n第二行"), '<br'));
    $check('Explicit Markdown line break remains supported', str_contains(Markdown::render("第一行  \n第二行"), '<br'));
    $escaped = Markdown::render('\*普通文本\* & < >');
    $check('Escaped markers and entities remain literal', !str_contains($escaped, '<em>')
        && str_contains($escaped, '*普通文本* &amp; &lt; &gt;'));
    $raw = Markdown::render('<img src="/x.png" onerror="alert(1)"><script>alert(1)</script>');
    $xpath = $document($raw);
    $check('Default safe mode keeps user HTML inert', $xpath->query('//script|//img|//*[@onerror]')->length === 0);
    $xpath = $document(Markdown::render('[danger](javascript:alert%281%29)'));
    $check('Default safe mode rejects executable link schemes', $xpath->query('//a[starts-with(@href,"javascript:")]')->length === 0);
    $xpath = $document(Markdown::render('[章节](#section) [外部](https://example.invalid)'));
    $check('Page anchors navigate within the current page', $xpath->query('//a[@href="#section" and not(@target)]')->length === 1);
    $check('Ordinary links retain opener isolation', $xpath->query('//a[@target="_blank" and @rel="noopener noreferrer"]')->length === 1);
    $footnote = "正文[^one]\n\n[^one]: 脚注 **加粗**";
    $first = Markdown::render($footnote);
    $second = Markdown::render($footnote);
    $check('Repeated renders restart footnote numbering', $first === $second);
    $xpath = $document($first);
    $check('Safe footnotes render paragraphs instead of escaped HTML', $xpath->query('//div[@class="footnotes"]//li/p/strong')->length === 1);
    $check('Footnote reference and return links stay on the page', $xpath->query('//a[contains(@class,"footnote-") and @target]')->length === 0
        && $xpath->query('//a[@class="footnote-ref"]')->length === 1
        && $xpath->query('//a[@class="footnote-backref"]')->length === 1);
    $xpath = $document(Markdown::render("引用[^x]\n\n[^x]: <script>alert(1)</script>"));
    $check('Allowing parser-generated footnote HTML does not enable user scripts', $xpath->query('//script')->length === 0
        && str_contains($xpath->document->textContent, '<script>'));
    $xpath = $document(Markdown::render("引用[^a\" onclick=\"alert(1)]\n\n[^a\" onclick=\"alert(1)]: 脚注"));
    $check('Footnote labels cannot inject HTML attributes', $xpath->query('//*[@onclick]|//script')->length === 0);
    $xpath = $document(Markdown::render("HTML\n\n*[HTML]: \" onmouseover=\"alert(1)"));
    $check('Abbreviation titles cannot inject HTML attributes', $xpath->query('//abbr')->length === 1
        && $xpath->query('//*[@onmouseover]')->length === 0);
    foreach (["- [x] 完成\n- [ ] 未完成", "- [x] 完成\n\n- [ ] 未完成"] as $i=>$source) {
        $xpath = $document(Markdown::render($source));
        $check('Task lists render for paragraph spacing ' . $i, $xpath->query('//input[@disabled]')->length === 2
            && $xpath->query('//input[@checked]')->length === 1);
    }
    $xpath = $document(Markdown::render("```md\n- [x] literal\n<script>alert(1)</script>\n```"));
    $check('Code examples remain escaped and are not task controls', $xpath->query('//pre/code')->length === 1
        && $xpath->query('//input|//script')->length === 0);
    $xpath = $document(Markdown::render("| 标题 |\n| --- |\n| 内容 |"));
    $check('Tables retain their overflow wrapper', $xpath->query('//div[@class="md-table-wrap"]/table')->length === 1);
    $check('Core HTML format continues to sanitize executable content', !str_contains(Content::render('<p>HTML</p><script>alert(1)</script>','html'), '<script'));
    Settings::set('md_allow_raw_html', '1');
    $check('Explicit raw HTML option remains an intentional opt-in', str_contains(Markdown::render('<span>HTML</span>'), '<span>HTML</span>'));
    Settings::set('md_allow_raw_html', '0');
    $check('Safe mode is restored between renders', !str_contains(Markdown::render('<span>HTML</span>'), '<span>HTML</span>'));
} finally {
    restore_error_handler();
    $pdo->cleanup(); $connection->setValue(null, null); Settings::reset();
}
echo $checks . ' passed' . PHP_EOL;
