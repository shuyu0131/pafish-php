<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
$project = dirname(__DIR__);
$root = sys_get_temp_dir() . '/pafish-cache-' . bin2hex(random_bytes(6));
mkdir($root . '/signals', 0750, true);
define('PAFISH_ROOT', $root);
require $project . '/vendor/autoload.php';
require $project . '/app/Core/helpers.php';
use Pafish\Core\{Cache, DB};
$passed = 0;
$check = static function(string $label, bool $ok) use (&$passed): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $label);
    $passed++; echo 'PASS ' . $label . PHP_EOL;
};
$file = static fn(string $key): string => $root . '/runtime/cache/' . sha1($key) . '.php';
$wait = static function(string $path): void {
    $deadline = microtime(true) + 8;
    while (true) {
        clearstatcache(true, $path);
        if (is_file($path)) return;
        if (microtime(true) >= $deadline) throw new RuntimeException('Fixture signal timed out');
        usleep(2000);
    }
};
$children = [];
$spawn = static function(string $action, string $key) use ($root, $project, &$children): array {
    $process = proc_open([PHP_BINARY, $project . '/tests/Fixtures/cache-worker.php', $root, $action, $key],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, $project);
    if (!is_resource($process)) throw new RuntimeException('Cannot start fixture worker');
    fclose($pipes[0]); $children[] = $process;
    return [$process, $pipes];
};
$join = static function(array $child): array {
    [$process,$pipes] = $child;
    $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0) throw new RuntimeException('Cache worker failed: ' . $error);
    return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
};
$report = [];
try {
    $check('Cache operations do not open a database connection',
        !DB::inTransaction() && (new ReflectionProperty(DB::class, 'pdo'))->getValue() === null);
    $check('Missing key returns caller default', Cache::get('test.missing', 'fallback') === 'fallback');
    foreach (['text', 0, false, null, [], ['key'=>'value']] as $i=>$value) {
        Cache::set('test.value.' . $i, $value);
        $check('Cached value preserves its type ' . $i, Cache::get('test.value.' . $i, new stdClass()) === $value);
    }
    Cache::set('test.replace', 'old'); Cache::set('test.replace','new');
    $check('Atomic replacement returns newest value', Cache::get('test.replace') === 'new');
    Cache::set('test.expired', 'expired', -1);
    $check('Nonpositive TTL retains existing no-expiry contract', Cache::get('test.expired') === 'expired');
    $expiredPayload = include $file('test.expired');
    $expiredPayload['expires'] = time();
    file_put_contents($file('test.expired'), '<?php return ' . var_export($expiredPayload, true) . ';');
    if (function_exists('opcache_invalidate')) opcache_invalidate($file('test.expired'), true);
    $check('Expiry applies at exact deadline', Cache::get('test.expired', 'miss') === 'miss');
    file_put_contents($file('test.corrupt'), '<?php return ["expires"=>0,"value"=>"wrong"];');
    $check('Payload without matching key is not returned', Cache::get('test.corrupt','miss') === 'miss');
    file_put_contents($file('test.syntax'), '<?php return [;');
    $check('Malformed cache file falls back instead of breaking page', Cache::get('test.syntax','miss')==='miss');
    $loads=0; $load=static function() use (&$loads): array { $loads++; return ['cached']; };
    Cache::resetStats();
    $check('Read-through loader fills and reuses shared value', Cache::remember('test.remember',$load)===['cached']
        && Cache::remember('test.remember',$load)===['cached'] && $loads===1);
    $check('Request-local counters expose hits loads and timing', Cache::stats()['hits']===1
        && Cache::stats()['loads']===1 && Cache::stats()['writes']===1 && Cache::stats()['load_ms']>=0);
    $emptyLoads=0; $empty=static function() use (&$emptyLoads): array { $emptyLoads++; return []; };
    cache_list('test.empty',$empty); cache_list('test.empty',$empty);
    $check('List helper still avoids caching empty installation data', $emptyLoads===2 && Cache::get('test.empty')===null);
    Cache::remember('test.empty-allowed',$empty); Cache::remember('test.empty-allowed',$empty);
    $check('Explicit read-through caching preserves empty arrays', $emptyLoads===3 && Cache::get('test.empty-allowed')===[]);
    Cache::resetStats();
    Cache::remember('test.unrelated',static function(): array { Cache::clearPrefix('another.'); return ['fresh']; });
    $check('Unrelated invalidation conservatively rejects only in-flight fill', Cache::get('test.unrelated')===null && Cache::stats()['rejected_fills']===1);
    try { Cache::remember('test.exception', static fn()=>throw new RuntimeException('expected')); }
    catch (RuntimeException $e) { $check('Loader exception propagates without partial cache', $e->getMessage()==='expected' && Cache::get('test.exception')===null); }
    $check('Loader exception releases fill lock', Cache::remember('test.exception',static fn()=>['recovered'])===['recovered']);
    foreach (['../escape', 'test..escape', 'test*', "bad\0key", "bad\n"] as $i=>$key) {
        try { Cache::remember($key,static fn()=>[]); throw new RuntimeException('accepted unsafe key'); }
        catch (InvalidArgumentException) { $check('Unsafe key rejected ' . $i, true); }
    }
    Cache::set('scope.one','one'); Cache::set('scope.two','two'); Cache::set('other.one','other');
    Cache::resetStats();
    Cache::clearPrefix('scope.');
    $check('Prefix invalidation does not scan cache payloads', Cache::stats()['scanned_files']===0);
    $check('Prefix invalidation removes only matching values', Cache::get('scope.one')===null
        && Cache::get('scope.two')===null && Cache::get('other.one')==='other');
    $check('Prefix invalidation is logical and retains files for reuse', is_file($file('scope.one')) && is_file($file('scope.two')));
    Cache::remember('scope.one',static fn()=>'replacement');
    $check('Invalidated keys can be filled in the same existing file',Cache::get('scope.one')==='replacement');
    Cache::set('nested.one.child','child'); Cache::set('nested.two','sibling');
    Cache::clearPrefix('nested.one'); Cache::set('nested.one.child','after-narrow');
    $check('Narrow prefix invalidation preserves siblings and later writes',Cache::get('nested.one.child')==='after-narrow' && Cache::get('nested.two')==='sibling');
    Cache::clearPrefix('nested.');
    $check('Broader invalidation covers earlier narrower markers and refills',Cache::get('nested.one.child')===null && Cache::get('nested.two')===null);
    Cache::set('newsletters','news'); Cache::set('newspaper','paper'); Cache::clearPrefix('news');
    $check('Prefix retains arbitrary string semantics without delimiter assumptions',Cache::get('newsletters')===null && Cache::get('newspaper')===null);
    Cache::set('0','zero'); Cache::set('01','leading-zero'); Cache::set('a:b/c.d','delimiters');
    Cache::clearPrefix('0'); Cache::clearPrefix('a:b/');
    $check('Numeric prefixes and allowed separators are matched literally',Cache::get('0')===null && Cache::get('01')===null && Cache::get('a:b/c.d')===null);
    Cache::resetStats(); Cache::clearPrefix('../'); Cache::clearPrefix('*'); Cache::clearPrefix("bad\n");
    $check('Impossible prefixes do not become globs or invalidate unrelated data',Cache::get('other.one')==='other' && Cache::stats()['invalidations']===0);
    Cache::set('delete.restore','old'); $saved=file_get_contents($file('delete.restore'));
    Cache::delete('delete.restore'); file_put_contents($file('delete.restore'),$saved);
    if(function_exists('opcache_invalidate'))opcache_invalidate($file('delete.restore'),true);
    $check('Exact deletion marker rejects an old file even when it survives',Cache::get('delete.restore')===null);
    Cache::set('delete.sibling','keep'); Cache::set('delete.restore','new');
    $check('Later direct write supersedes deletion without invalidating siblings',Cache::get('delete.restore')==='new' && Cache::get('delete.sibling')==='keep');
    Cache::delete('other.one');
    $check('Direct deletion invalidates exact key', Cache::get('other.one')===null);
    $stale = cache_list('race.sync',static function(): array { Cache::clearPrefix('race.'); return ['old']; });
    $check('Invalidation during loader prevents stale publication', $stale===['old'] && Cache::get('race.sync')===null);
    Cache::remember('race.set',static function(): array { Cache::set('race.set',['new']); return ['old']; });
    $check('Explicit writes win over older loader', Cache::get('race.set')===['new']);
    Cache::remember('race.delete',static function(): array { Cache::delete('race.delete'); return ['old']; });
    $check('Deleting absent key fences an in-flight fill', Cache::get('race.delete')===null);
    Cache::remember('race.clear',static function(): array { Cache::clear(); return ['old']; });
    $check('Full clear fences loader and preserves synchronization', Cache::get('race.clear')===null
        && is_file($root . '/runtime/cache/.cache.lock'));

    $child = $spawn('slow','parallel.slow'); $wait($root.'/signals/ready');
    $started = hrtime(true); $join($spawn('invalidate','parallel.'));
    $report['invalidation_during_loader_ms']=(hrtime(true)-$started)/1000000;
    file_put_contents($root.'/signals/release','1');
    $result=$join($child);
    $check('Independent process invalidation does not deadlock behind query', $result['value']===['old']);
    $check('Independent process cannot republish invalidated data', Cache::get('parallel.slow')===null && $result['stats']['rejected_fills']===1);
    $check('Next request loads new snapshot', Cache::remember('parallel.slow',static fn()=>['fresh'])===['fresh']);
    Cache::set('multi.keep','keep');
    $invalidators=[];
    for($i=0;$i<4;$i++) {
        Cache::set('multi.'.$i.'.value',$i);
        $invalidators[]=$spawn('prefix-herd','multi.'.$i.'.');
    }
    $deadline=microtime(true)+8;
    while(count(glob($root.'/signals/invalidate-started-*'))<4) {
        if(microtime(true)>$deadline)throw new RuntimeException('Invalidator startup timed out');
        usleep(2000);
    }
    file_put_contents($root.'/signals/invalidate-go','1');
    $invalidated=array_map($join,$invalidators);
    $allInvalid=true;
    for($i=0;$i<4;$i++)$allInvalid=$allInvalid && Cache::get('multi.'.$i.'.value','miss')==='miss';
    $check('Concurrent independent prefix invalidations preserve every marker',$allInvalid
        && count(array_filter($invalidated,static fn($r)=>$r['value']==='invalidated' && $r['stats']['scanned_files']===0))===4);
    $check('Concurrent prefix invalidations preserve unrelated cached values',Cache::get('multi.keep')==='keep');
    $herd=[]; for($i=0;$i<4;$i++) $herd[]=$spawn('herd','parallel.herd');
    $deadline=microtime(true)+8;
    while(count(glob($root.'/signals/started-*'))<4) {
        if(microtime(true)>$deadline) throw new RuntimeException('Herd startup timed out');
        usleep(2000);
    }
    file_put_contents($root.'/signals/go','1');
    $results=array_map($join,$herd);
    $loadCount=count(file($root.'/signals/loads',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES));
    $report['concurrent_workers']=count($results); $report['actual_loads']=$loadCount;
    $check('Four concurrent misses share one loader within wait budget', $loadCount===1);
    $check('Concurrent requests receive same fresh result', count(array_filter($results,static fn($r)=>$r['value']===['fresh']))===4);
    $lock=fopen($root.'/runtime/cache/.fill-'.substr(sha1('test.timeout'),0,2).'.lock','c+b');
    flock($lock,LOCK_EX);
    try {
        Cache::resetStats(); $started=hrtime(true);
        $result=Cache::remember('test.timeout',static fn()=>['fallback']);
        $report['fill_lock_timeout_ms']=(hrtime(true)-$started)/1000000;
        $check('Contended fill lock falls back without publishing', $result===['fallback']
            && Cache::get('test.timeout')===null && Cache::stats()['lock_timeouts']===1 && Cache::stats()['uncached_loads']===1);
    } finally { flock($lock,LOCK_UN); fclose($lock); }
    for($i=0;$i<100;$i++) Cache::remember('stripe.' . $i,static fn()=>1);
    $check('Fill lock files remain bounded to 256 stripes', count(glob($root.'/runtime/cache/.fill-*.lock'))<=256);
    Cache::clear();
    $check('Full clear removes values and leaves stable lock file', glob($root.'/runtime/cache/*.php')===[]
        && is_file($root.'/runtime/cache/.cache.lock'));
    $lockPath=$root.'/runtime/cache/.cache.lock';
    Cache::set('rollover.old','old');
    $oldState=json_decode(file_get_contents($lockPath),true,512,JSON_THROW_ON_ERROR);
    for($i=0;$i<300;$i++)Cache::clearPrefix('dynamic.'.$i.'.');
    $newState=json_decode(file_get_contents($lockPath),true,512,JSON_THROW_ON_ERROR);
    $check('Distinct dynamic prefixes have bounded metadata and safe epoch rollover',count($newState['prefixes'])+count($newState['keys'])<=256
        && $oldState['epoch']!==$newState['epoch'] && Cache::get('rollover.old')===null);
    $check('Cache refills normally after marker rollover',Cache::remember('rollover.old',static fn()=>'new')==='new' && Cache::get('rollover.old')==='new');
    Cache::clear(); Cache::set('delete.rollover','old');
    $oldEpoch=json_decode(file_get_contents($lockPath),true)['epoch'];
    for($i=0;$i<300;$i++)Cache::delete('deleted.'.$i);
    $deleteState=json_decode(file_get_contents($lockPath),true);
    $check('Dynamic exact deletion markers are bounded with the same rollover',count($deleteState['keys'])+count($deleteState['prefixes'])<=256
        && $deleteState['epoch']!==$oldEpoch && Cache::get('delete.rollover')===null);
    Cache::set('compact.one.a','one'); Cache::clearPrefix('compact.one.'); Cache::delete('compact.one.a'); Cache::clearPrefix('compact.');
    $compact=json_decode(file_get_contents($lockPath),true);
    $check('Broader prefix replaces covered prefix and exact markers',isset($compact['prefixes']['compact.'])
        && !isset($compact['prefixes']['compact.one.']) && !isset($compact['keys']['compact.one.a']));
    Cache::set('state.recovery','old');
    foreach(['{broken',str_repeat('a',32)] as $index=>$broken) {
        file_put_contents($lockPath,$broken);
        $check('Malformed or previous state cannot return old cache '.$index,Cache::get('state.recovery','miss')==='miss');
        Cache::remember('state.recovery',static fn()=>'recovered-'.$index);
        $check('Malformed or previous state safely cold-starts on refill '.$index,Cache::get('state.recovery')==='recovered-'.$index);
    }
    file_put_contents($file('legacy.payload'),'<?php return ["key"=>"legacy.payload","value"=>"legacy","expires"=>0];');
    $check('Old payload format is treated as a cold miss',Cache::get('legacy.payload','miss')==='miss');
    $legacyResult=Cache::remember('legacy.payload',static fn()=>'fresh');
    $check('Old payload is replaced through normal loading',$legacyResult==='fresh' && Cache::get('legacy.payload')==='fresh');
    $integerState=json_decode(file_get_contents($lockPath),true,512,JSON_THROW_ON_ERROR);
    $integerState['revision']=PHP_INT_MAX;
    file_put_contents($lockPath,json_encode($integerState,JSON_THROW_ON_ERROR));
    Cache::set('integer.rollover','new');
    $check('Revision integer overflow starts a fresh epoch without a type error',Cache::get('integer.rollover')==='new'
        && json_decode(file_get_contents($lockPath),true)['epoch']!==$integerState['epoch']);
    Cache::set('failure.write','old');$beforeFailure=file_get_contents($file('failure.write'));
    unlink($file('failure.write'));mkdir($file('failure.write'));
    try {
        Cache::resetStats(); Cache::set('failure.write','replacement');
        $failedStats=Cache::stats();
    } finally { rmdir($file('failure.write')); }
    file_put_contents($file('failure.write'),$beforeFailure);
    if(function_exists('opcache_invalidate'))opcache_invalidate($file('failure.write'),true);
    $check('Failed replacement records an error and rejects the previous snapshot',$failedStats['errors']>0
        && $failedStats['writes']===0 && Cache::get('failure.write','miss')==='miss');
    $check('Failed publication leaves no temporary files behind',glob($file('failure.write').'.*')===[]);
    $check('Replacement recovers after filesystem obstruction is removed',Cache::remember('failure.write',static fn()=>'recovered')==='recovered'
        && Cache::get('failure.write')==='recovered');
    rename($lockPath,$lockPath.'.saved'); mkdir($lockPath);
    try {
        Cache::resetStats();
        $value=Cache::remember('failure.lock',static fn()=>['database-result']);
        $check('Unavailable cache lock returns source result without publishing', $value===['database-result']
            && Cache::stats()['uncached_loads']===1 && Cache::stats()['errors']>0 && !is_file($file('failure.lock')));
    } finally { rmdir($lockPath); rename($lockPath.'.saved',$lockPath); }
    $check('Cache test created no database connection', (new ReflectionProperty(DB::class,'pdo'))->getValue()===null);
    $report['passed']=$passed; $report['failed']=0;
    if(in_array('--report',$argv,true)) file_put_contents($project.'/.design-review/cache-contract.json',json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    echo json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),PHP_EOL;
} finally {
    foreach($children as $process) if(is_resource($process)) { proc_terminate($process); proc_close($process); }
    $remove=static function(string $path) use (&$remove): void {
        if(is_dir($path) && !is_link($path)) {
            foreach(scandir($path) as $name) if($name!=='.' && $name!=='..') $remove($path.'/'.$name);
            rmdir($path);
        } else unlink($path);
    };
    $remove($root);
}
