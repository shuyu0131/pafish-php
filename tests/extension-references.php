<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('PAFISH_ROOT', dirname(__DIR__));
require PAFISH_ROOT.'/vendor/autoload.php';
require __DIR__.'/Fixtures/MySql.php';

use Pafish\Core\{Config,DB,SqlScript};
use Pafish\Services\{ExtensionSchema,Settings};
use Pafish\Tests\MySql;

Config::load(Config::resolveFile(PAFISH_ROOT));
$count=0;
$check=static function(string $label,bool $ok)use(&$count):void{
    if(!$ok)throw new RuntimeException('FAIL '.$label);
    $count++;echo 'PASS '.$label.PHP_EOL;
};
$reject=static function(callable $fn):bool{try{$fn();}catch(Throwable){return true;}return false;};
$pdo=new MySql('qa_core_'.bin2hex(random_bytes(6)).'_');
$connection=new ReflectionProperty(DB::class,'pdo');$connection->setValue(null,$pdo);
$column=['name'=>'user_id','type'=>'bigint','unsigned'=>true,'nullable'=>true,'default'=>null,
    'references'=>['table'=>'users','onDelete'=>'SET NULL']];
$schema=static fn(array $col):array=>['tables'=>[['name'=>'records','columns'=>[
    ['name'=>'id','type'=>'bigint','unsigned'=>true,'autoIncrement'=>true],$col
],'primary'=>['id']]]];
$sync=static fn(array $s)=>ExtensionSchema::sync('plugin','qa_foundation',$s);
try{
    foreach(SqlScript::split(file_get_contents(PAFISH_ROOT.'/app/install/schema.sql'))as$sql)$pdo->query($sql);
    $check('Typed core reference is valid',ExtensionSchema::validateDeclaration($schema($column),'plugin','qa_foundation')===null);
    foreach([
        ['type'=>'int'],['unsigned'=>false],['nullable'=>false],
        ['references'=>['table'=>'settings']],['references'=>['table'=>'users; DROP TABLE users']],
        ['references'=>['table'=>'users','column'=>'email']],
        ['references'=>['table'=>'users','onDelete'=>'NO ACTION; SELECT 1']],
        ['references'=>['table'=>'users','onUpdate'=>'SET DEFAULT']],
        ['references'=>['table'=>'users','unknown'=>'value']]
    ]as$i=>$bad){
        $check('Invalid reference declaration rejected '.$i,ExtensionSchema::validateDeclaration($schema(array_replace($column,$bad)),'plugin','qa_foundation')!==null);
    }
    $sync($schema($column));
    $sync($schema($column));
    $check('Fresh FK installation and repeated sync succeed',true);
    $check('Foreign key blocks orphan insert',$reject(fn()=>DB::execute('INSERT INTO pf_plugin_qa_foundation_records(user_id)VALUES(999999)')));
    DB::execute("INSERT INTO users(id,username,email,password_hash,role)VALUES(1,'fixture','fixture@example.invalid','unused','ADMIN')");
    DB::execute('INSERT INTO pf_plugin_qa_foundation_records(user_id)VALUES(1)');
    DB::execute('DELETE FROM users WHERE id=1');
    $check('SET NULL preserves extension row on user deletion',DB::fetchOne('SELECT * FROM pf_plugin_qa_foundation_records')['user_id']===null);
    $check('Changed delete rule requires explicit migration',$reject(fn()=>$sync($schema(array_replace($column,[
        'references'=>['table'=>'users','onDelete'=>'CASCADE']
    ])))));
    $check('Changed target requires explicit migration',$reject(fn()=>$sync($schema(array_replace($column,[
        'references'=>['table'=>'posts','onDelete'=>'SET NULL']
    ])))));
    DB::execute('DROP TABLE pf_plugin_qa_foundation_records');
    // A legacy table gets its FK without changing its existing records.
    $old=$column;unset($old['references']);
    $sync($schema($old));
    DB::execute('INSERT INTO pf_plugin_qa_foundation_records(user_id)VALUES(123456)');
    $check('Orphaned legacy data blocks automatic FK addition',$reject(fn()=>$sync($schema($column))));
    $check('Failed sync leaves legacy data intact',(int)DB::value('SELECT user_id FROM pf_plugin_qa_foundation_records')===123456);
    DB::execute('UPDATE pf_plugin_qa_foundation_records SET user_id=NULL');
    $sync($schema($column));
    $check('Explicitly repaired data can be synced',$reject(fn()=>DB::execute('INSERT INTO pf_plugin_qa_foundation_records(user_id)VALUES(123456)')));
    DB::execute('DROP TABLE pf_plugin_qa_foundation_records');
    $sync($schema(array_replace($old,['type'=>'int'])));
    $check('Existing wrong-width reference is not silently modified',$reject(fn()=>$sync($schema($column))));
    DB::execute('DROP TABLE pf_plugin_qa_foundation_records');
    $required=array_replace($column,['nullable'=>false,'default'=>0,'references'=>['table'=>'users','onDelete'=>'CASCADE']]);
    $sync($schema($required));
    DB::execute("INSERT INTO users(id,username,email,password_hash,role)VALUES(2,'fixture2','fixture2@example.invalid','unused','ADMIN')");
    DB::execute('INSERT INTO pf_plugin_qa_foundation_records(user_id)VALUES(2)');
    DB::execute('DELETE FROM users WHERE id=2');
    $check('Explicit CASCADE cleans plugin dependent data',(int)DB::value('SELECT COUNT(*) FROM pf_plugin_qa_foundation_records')===0);
    DB::execute('DROP TABLE pf_plugin_qa_foundation_records');
    $required['references']=['table'=>'users'];
    $sync($schema($required));
    DB::execute("INSERT INTO users(id,username,email,password_hash,role)VALUES(3,'fixture3','fixture3@example.invalid','unused','ADMIN')");
    DB::execute('INSERT INTO pf_plugin_qa_foundation_records(user_id)VALUES(3)');
    $check('Default RESTRICT blocks parent deletion',$reject(fn()=>DB::execute('DELETE FROM users WHERE id=3')));
}finally{
    $pdo->cleanup();$connection->setValue(null,null);Settings::reset();
}
echo $count.' passed'.PHP_EOL;
