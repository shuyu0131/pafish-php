<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ob_start();
define('PAFISH_ROOT',dirname(__DIR__));
require PAFISH_ROOT.'/vendor/autoload.php';
require PAFISH_ROOT.'/app/Core/helpers.php';
require __DIR__.'/Fixtures/MySql.php';

use Pafish\Core\{Auth, Config, DB, RateLimiter, Session, SqlScript};
use Pafish\Services\{EmailCode, Migrator, Settings};
use Pafish\Http\AuthApiController;
use Pafish\Tests\MySql;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

Config::load(Config::resolveFile(PAFISH_ROOT));
date_default_timezone_set('Asia/Shanghai');
Session::start();
$pdo=new MySql('qa_core_'.bin2hex(random_bytes(6)).'_');
(new ReflectionProperty(DB::class,'pdo'))->setValue(null,$pdo);
$passed=0;
$check=static function(string $label,bool $ok) use (&$passed): void {
    if (!$ok) throw new RuntimeException('FAIL '.$label);
    $passed++; echo 'PASS '.$label.PHP_EOL;
};
$factory=new ServerRequestFactory(); $controller=new AuthApiController();
$ip='198.18.'.random_int(1,254).'.'.random_int(1,254);
$emails=[$pdo->prefix.'@invalid.test',$pdo->prefix.'other@invalid.test'];
$call=static function(string $action,array $body) use ($controller,$factory,$ip): Response {
    return $controller->$action($factory->createServerRequest('POST','/api/auth/'.$action,['REMOTE_ADDR'=>$ip])->withParsedBody($body),new Response());
};
$clearSend=static fn(string $email,string $purpose)=>RateLimiter::clear('email-code-send',strtolower(trim($email))."\0".$purpose);
try {
    foreach(SqlScript::split(file_get_contents(PAFISH_ROOT.'/app/install/schema.sql')) as $sql) $pdo->query($sql);
    $check('Fresh install has no email credential table',(int)DB::value('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',['email_codes'])===0);
    Migrator::run($pdo,PAFISH_ROOT.'/migrations');
    $check('Fresh migrations do not recreate retired email storage',(int)DB::value('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',['email_codes'])===0);
    foreach (['a..b@example.test', '鱼@example.test', str_repeat('a', 256).'@example.test'] as $invalidEmail) {
        $check('Invalid code mailbox returns a client error at byte length '.strlen($invalidEmail),
            $call('sendCode',['email'=>$invalidEmail,'purpose'=>'register'])->getStatusCode()===400);
    }
    $queries=[]; $pdo->afterExecute=static function(string $sql) use (&$queries): void { $queries[]=$sql; };
    [$code,$limited]=EmailCode::create('  '.strtoupper($emails[0]).'  ','register');
    $record=Session::get('email_code:register');
    $check('Email codes generate and verify without any database queries',!$limited && $queries===[]);
    $check('Session binds normalized mailbox, hash and ten-minute expiry',$record['email']===$emails[0] && $record['hash']!==$code
        && password_verify($code,$record['hash']) && $record['expires']>time()+590 && $record['expires']<=time()+600);
    $check('Six-digit code shape remains unchanged',preg_match('/^\d{6}$/D',$code)===1);
    $check('Different mailbox cannot redeem a code',!EmailCode::verify($emails[1],'register',$code));
    $check('Register and reset purposes cannot exchange codes',!EmailCode::verify($emails[0],'reset',$code));
    $ownSession=$_SESSION; $_SESSION=[];
    $check('A different browser session cannot redeem the code',!EmailCode::verify($emails[0],'register',$code));
    $_SESSION=$ownSession;
    $check('Valid code succeeds exactly once',EmailCode::verify($emails[0],'register',$code) && !EmailCode::verify($emails[0],'register',$code));
    $check('Consumed session record is removed',Session::get('email_code:register')===null);
    $check('Mailbox sending interval survives session record consumption',EmailCode::create($emails[0],'register')[1]);
    $clearSend($emails[0],'register');
    [$new]=EmailCode::create($emails[0],'register');
    $record=Session::get('email_code:register'); $record['expires']=time(); Session::set('email_code:register',$record);
    $check('Expiry is enforced at the exact deadline',!EmailCode::verify($emails[0],'register',$new) && Session::get('email_code:register')===null);
    $clearSend($emails[0],'register'); [$new]=EmailCode::create($emails[0],'register');
    $wrong=$new==='000000'?'111111':'000000';
    for($i=0;$i<5;$i++) EmailCode::verify($emails[0],'register',$wrong);
    $check('Five failed guesses invalidate the code even if IP changes',!EmailCode::verify($emails[0],'register',$new) && Session::get('email_code:register')===null);
    $clearSend($emails[0],'register'); EmailCode::create($emails[0],'register');
    [$replacement]=EmailCode::create($emails[1],'register');
    $check('Only one mailbox challenge is retained per purpose',!EmailCode::verify($emails[0],'register',$code)
        && Session::get('email_code:register')['email']===$emails[1]);
    EmailCode::forget('register');
    $check('Explicit revocation invalidates an undelivered code',!EmailCode::verify($emails[1],'register',$replacement));
    $pdo->afterExecute=null;
    foreach([str_repeat('a',72),str_repeat('鱼',24),'secret6'] as $password) $check('Accepted password respects bcrypt byte limit '.strlen($password),Auth::validPassword($password));
    foreach([str_repeat('a',73),str_repeat('鱼',25),"valid\0password",'short'] as $password) $check('Unsafe password rejected at byte length '.strlen($password),!Auth::validPassword($password));

    $hash=password_hash('original-password',PASSWORD_BCRYPT,['cost'=>10]);
    DB::execute("INSERT INTO users(id,username,email,password_hash,role) VALUES(1,'qa-auth',?,?,'ADMIN')",[$emails[0],$hash]);
    Settings::setMany(['allow_registration'=>'true','require_email_verify'=>'true']);
    $clearSend($emails[1],'register'); [$registerCode]=EmailCode::create($emails[1],'register');
    $response=$call('register',['username'=>'qa-new','email'=>$emails[1],'password'=>'new-password','code'=>$registerCode]);
    $check('Real registration accepts the current session code',$response->getStatusCode()===200 && (int)DB::value("SELECT COUNT(*) FROM users WHERE username='qa-new'")===1);
    // A deliberately closed loopback SMTP endpoint; never deliver mail to anyone.
    Settings::setMany(['smtp_host'=>'127.0.0.1','smtp_port'=>'1','smtp_user'=>'fixture','smtp_pass'=>'fixture','smtp_from'=>'fixture@invalid.test']);
    $clearSend($emails[1],'reset');
    $response=$call('sendCode',['email'=>$emails[1],'purpose'=>'reset']);
    $check('Failed email delivery revokes the new session challenge',$response->getStatusCode()===400 && Session::get('email_code:reset')===null);
    $check('Failed delivery retains send throttling',EmailCode::create($emails[1],'reset')[1]);
    $clearSend($emails[0],'reset'); [$resetCode]=EmailCode::create($emails[0],'reset');
    $codeResetToken=\Pafish\Services\AppTokens::issue(1,'code-reset-test');
    $response=$call('resetByCode',['email'=>$emails[0],'code'=>$resetCode,'newPassword'=>'changed-password']);
    $check('Real password reset accepts its bound session challenge',$response->getStatusCode()===200 && password_verify('changed-password',(string)DB::value('SELECT password_hash FROM users WHERE id=1')));
    $revokedCodeToken=DB::value('SELECT revoked_at FROM app_tokens WHERE id=?',[$codeResetToken['id']]);
    $check('Email-code password reset revokes all app devices',$revokedCodeToken !== null);
    $check('Real code reset rejects replay',$call('resetByCode',['email'=>$emails[0],'code'=>$resetCode,'newPassword'=>'replayed-password'])->getStatusCode()===400);
    $check('Too-long multibyte reset password is rejected before changing credentials',$call('resetByCode',['email'=>$emails[0],'code'=>'123456','newPassword'=>str_repeat('鱼',25)])->getStatusCode()===400);

    $token=bin2hex(random_bytes(32));
    DB::execute('UPDATE users SET reset_token=?,reset_token_expires=DATE_ADD(NOW(),INTERVAL 30 MINUTE) WHERE id=1',[$token]);
    $check('Malformed reset token is rejected',$call('reset',['token'=>'bad-token','password'=>'new-password'])->getStatusCode()===400);
    // Four independent connections deterministically complete SELECT before any UPDATE.
    DB::execute('CREATE TABLE fixture_log(id INT PRIMARY KEY,value VARCHAR(255)) ENGINE=InnoDB');
    DB::execute("INSERT INTO fixture_log VALUES(1,'0')");
    $workers=[];
    for($i=0;$i<4;$i++) {
        $process=proc_open([PHP_BINARY,__DIR__.'/Fixtures/worker.php','reset_race',$pdo->prefix,$token,'4'],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,PAFISH_ROOT);
        if(!is_resource($process)) throw new RuntimeException('Cannot start reset worker');
        fclose($pipes[0]); $workers[]=[$process,$pipes];
    }
    $results=[];
    foreach($workers as [$process,$pipes]) {
        $raw=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        if(proc_close($process)!==0) throw new RuntimeException($error);
        $results[]=json_decode($raw,true,512,JSON_THROW_ON_ERROR)['result'];
    }
    sort($results);
    $check('A reset link can succeed once across concurrent requests',$results===[200,400,400,400]);
    $check('Winning reset clears token and stores the new password',DB::value('SELECT reset_token FROM users WHERE id=1')===null
        && password_verify('parallel-password',(string)DB::value('SELECT password_hash FROM users WHERE id=1')));
    $check('Reset link cannot be replayed',$call('reset',['token'=>$token,'password'=>'replay-password'])->getStatusCode()===400);
    $expired=bin2hex(random_bytes(32));
    DB::execute('UPDATE users SET reset_token=?,reset_token_expires=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE id=1',[$expired]);
    $check('Expired link cannot reset a password',$call('reset',['token'=>$expired,'password'=>'new-password'])->getStatusCode()===400);
    DB::execute('UPDATE users SET disabled=1,reset_token=?,reset_token_expires=DATE_ADD(NOW(),INTERVAL 30 MINUTE) WHERE id=1',[$expired]);
    $check('Disabled account cannot use a reset link',$call('reset',['token'=>$expired,'password'=>'new-password'])->getStatusCode()===403);
    DB::execute('UPDATE users SET disabled=0 WHERE id=1');
    Session::set('user_id',1); (new ReflectionProperty(Auth::class,'user'))->setValue(null,null);
    $profileToken=\Pafish\Services\AppTokens::issue(1,'profile-password-test');
    $response=(new Pafish\Admin\ProfileController())->changePassword($factory->createServerRequest('POST','/admin/profile/password')->withParsedBody([
        '_csrf'=>Session::csrfToken(),'current_password'=>'parallel-password','new_password'=>'profile-password']),new Response());
    $check('Profile password change also revokes previously issued reset links',$response->getStatusCode()===200 && DB::value('SELECT reset_token FROM users WHERE id=1')===null);
    $check('Profile password change revokes app devices atomically',(int)DB::value('SELECT revoked_at IS NOT NULL FROM app_tokens WHERE id=?',[$profileToken['id']])===1);
    Settings::set('blocked_ips','[]');
    Settings::get('blocked_ips');
    (new ReflectionProperty(Auth::class,'user'))->setValue(null,null);
    $response=(new Pafish\Admin\CommentsController())->blockIp($factory->createServerRequest('POST','/admin/comments/block-ip')->withParsedBody(['ip'=>'192.0.2.1']),new Response());
    $check('Comment blocking uses shared settings and invalidates the request snapshot',$response->getStatusCode()===200 && json_decode(Settings::get('blocked_ips'),true)===['192.0.2.1']);
} finally {
    $pdo->afterExecute=null; $pdo->cleanup(); Settings::reset();
    foreach($emails as $email) foreach(['register','reset'] as $purpose) {
        $clearSend($email,$purpose); RateLimiter::clear('auth-code-verify',$ip.'|'.$email.'|'.$purpose);
    }
    RateLimiter::clear('auth-code-send-ip',$ip);
    EmailCode::forget('register'); EmailCode::forget('reset'); Auth::logout();
}
echo json_encode(['passed'=>$passed,'failed'=>0])."\n";
ob_end_flush();
