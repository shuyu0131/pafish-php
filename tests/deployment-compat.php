<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('PAFISH_ROOT', dirname(__DIR__));
require PAFISH_ROOT . '/vendor/autoload.php';

use Pafish\Core\{Config, Url};
use Pafish\Http\PostController;

$passed = $failed = 0;
$check = static function (string $label, bool $ok) use (&$passed, &$failed): void {
    $ok ? $passed++ : $failed++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
};
$temp = PAFISH_ROOT . '/runtime/qa_compat_' . bin2hex(random_bytes(6));
if (is_dir($temp) || !mkdir($temp) || !mkdir($temp . '/runtime')) throw new RuntimeException('Cannot create isolated configuration fixture');
$server = $_SERVER;
$config = new ReflectionProperty(Config::class, 'data');
$original = $config->getValue();
try {
    $check('Missing configuration returns null for installer', Config::resolveFile($temp) === null);
    file_put_contents($temp.'/config.php','<?php return ["marker"=>"root-fixture"];');
    $check('Root-only installed sites are still recognized', Config::resolveFile($temp) === $temp.'/config.php');
    file_put_contents($temp.'/runtime/config.php','<?php return ["marker"=>"runtime-fixture"];');
    $check('Runtime configuration takes priority over old root config', Config::resolveFile($temp) === $temp.'/runtime/config.php');
    Config::load(Config::resolveFile($temp));
    $check('Configuration resolution loads only the selected file', Config::get('marker') === 'runtime-fixture');
    $config->setValue(null,['pretty_urls'=>true]);
    Url::init('/index.php');
    $_SERVER['DOCUMENT_ROOT']=PAFISH_ROOT;
    $rootAsset = Url::asset('css/admin.css');
    $check('Root deployment currently generates public-prefixed assets', str_starts_with($rootAsset,'/public/css/admin.css?v='));
    $_SERVER['DOCUMENT_ROOT']=PAFISH_ROOT.'/public';
    $check('Public document root generates prefix-free assets', str_starts_with(Url::asset('css/admin.css'),'/css/admin.css?v='));
    Url::init('/blog/index.php');
    $_SERVER['DOCUMENT_ROOT']=PAFISH_ROOT;
    $check('Subdirectory deployment keeps asset base and public prefix', str_starts_with(Url::asset('js/admin-editor.js'),'/blog/public/js/admin-editor.js?v='));
    $config->setValue(null,['pretty_urls'=>false]);
    $check('Query routing keeps the subdirectory prefix', Url::to('/admin/extensions/data')==='/blog/index.php?p=admin/extensions/data');

    $verify = new ReflectionMethod(PostController::class,'verifyPassword');
    $controller = new PostController();
    $bcrypt=password_hash('fixture-password',PASSWORD_BCRYPT,['cost'=>4]);
    $check('Bcrypt article password still works', $verify->invoke($controller,'fixture-password',$bcrypt));
    $check('Bcrypt wrong password is rejected', !$verify->invoke($controller,'wrong',$bcrypt));
    $check('Bcrypt hash itself is not the article password', !$verify->invoke($controller,$bcrypt,$bcrypt));
    $check('Legacy plain imported password still works pending explicit migration', $verify->invoke($controller,'old-plain','old-plain'));
    $check('Legacy plain mismatch is rejected', !$verify->invoke($controller,'wrong','old-plain'));
    foreach (['$2y$broken','$argon2id$broken'] as $broken) {
        $check('Malformed hash cannot become a plaintext credential: '.$broken, !$verify->invoke($controller,$broken,$broken));
    }
    if (defined('PASSWORD_ARGON2ID')) {
        $argon=password_hash('fixture-password',PASSWORD_ARGON2ID,['memory_cost'=>8192,'time_cost'=>1,'threads'=>1]);
        $check('Argon2 article passwords are verified by their algorithm', $verify->invoke($controller,'fixture-password',$argon));
        $check('Argon2 wrong password is rejected', !$verify->invoke($controller,'wrong',$argon));
        $check('Argon2 hash itself cannot unlock the article', !$verify->invoke($controller,$argon,$argon));
    }
} finally {
    $_SERVER=$server;
    $config->setValue(null,$original);
    Url::init($_SERVER['SCRIPT_NAME']??'/index.php');
    foreach ([$temp.'/config.php',$temp.'/runtime/config.php'] as $file) if (is_file($file)) unlink($file);
    rmdir($temp.'/runtime'); rmdir($temp);
}
echo json_encode(['passed'=>$passed,'failed'=>$failed])."\n";
exit($failed>0?1:0);
