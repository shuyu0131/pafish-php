<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit(1);
$project=dirname(__DIR__);
$root=sys_get_temp_dir().'/pafish-cache-'.bin2hex(random_bytes(6));
mkdir($root.'/runtime/cache',0750,true);
define('PAFISH_ROOT',$root);
require $project.'/vendor/autoload.php';
use Pafish\Core\Cache;
$median=static function(callable $fn,int $iterations): float {
    $runs=[];
    for($run=0;$run<5;$run++) {
        $start=hrtime(true); for($i=0;$i<$iterations;$i++) $fn($i);
        $runs[]=(hrtime(true)-$start)/1000000/$iterations;
    }
    sort($runs); return $runs[2];
};
try {
    Cache::set('bench.hit',['value'=>'public data']);
    $path=$root.'/runtime/cache/'.sha1('bench.hit').'.php';
    $legacy=static function() use ($path): mixed {
        if(!is_file($path))return null;
        $payload=include $path;
        return $payload['value']??null;
    };
    $before=$median($legacy,1000);
    $after=$median(static fn()=>Cache::get('bench.hit'),1000);
    $remember=$median(static fn()=>Cache::remember('bench.hit',static fn()=>null),1000);
    $prefix=[];
    foreach([10,100,1000] as $count) {
        Cache::clear();
        for($i=0;$i<$count;$i++) Cache::set('bulk.'.$i,['item'=>$i]);
        $legacyScan=$median(static function() use($root): void {
            foreach(glob($root.'/runtime/cache/*.php')?:[] as $file) {
                $payload=include $file;
                if(is_array($payload) && str_starts_with((string)($payload['key']??''),'unrelated.'))unlink($file);
            }
        },1);
        $runs=[]; $stats=[];
        for($i=0;$i<5;$i++) {
            Cache::resetStats(); $start=hrtime(true); Cache::clearPrefix('unrelated.');
            $runs[]=(hrtime(true)-$start)/1000000; $stats=Cache::stats();
        }
        sort($runs);
        $nonmatching=$runs[2];
        $matched=[];
        for($i=0;$i<5;$i++) {
            Cache::set('bulk.0',['fresh'=>$i]);
            Cache::resetStats();$start=hrtime(true);Cache::clearPrefix('bulk.');
            $matched[]=(hrtime(true)-$start)/1000000;
            if(Cache::get('bulk.0','miss')!=='miss')throw new RuntimeException('Matching invalidation failed');
        }
        sort($matched);
        $prefix[]=['files'=>$count,'previous_payload_scan_ms'=>$legacyScan,'nonmatching_median_ms'=>$nonmatching,
            'matching_median_ms'=>$matched[2],'scanned_files'=>$stats['scanned_files']];
    }
    $markerHits=[];
    foreach([0,64,256] as $markers) {
        Cache::clear();
        for($i=0;$i<$markers;$i++)Cache::clearPrefix('unrelated-marker.'.$i.'.');
        Cache::set('bench.markers',['value'=>'public data']);
        $markerHits[]=['markers'=>$markers,'hit_ms'=>$median(static fn()=>Cache::get('bench.markers'),1000)];
    }
    $report=['environment'=>['php'=>PHP_VERSION,'os'=>PHP_OS_FAMILY,'scope'=>'isolated local file cache; warm filesystem; five median runs'],
        'warm_hit_ms'=>['previous_unlocked_include'=>$before,'shared_lock_get'=>$after,'remember_hit'=>$remember],
        'prefix_invalidation'=>$prefix,
        'bounded_marker_hit_cost'=>$markerHits,
        'limits'=>'No millisecond pass threshold; not production or whole-page throughput. Prefixes are logical invalidations; full clear still scans to reclaim files. Previous scan comparison is an unlocked lower-bound replay on the same payloads.'];
    if(in_array('--report',$argv,true))file_put_contents($project.'/.design-review/cache-prefix-performance.json',json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    echo json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),PHP_EOL;
} finally {
    $remove=static function(string $path)use(&$remove):void {
        if(is_dir($path)&&!is_link($path)){foreach(scandir($path)as$name)if($name!=='.'&&$name!=='..')$remove($path.'/'.$name);rmdir($path);}
        else unlink($path);
    };
    $remove($root);
}
