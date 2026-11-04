<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../src/CacheManager.php';

function cache_test_key(array $p, int $now = 1800000029): string {
    return (new CacheManager('/cache'))->computeKey(new RenderSpec($p, $now));
}

test('Equivalent absolute moments, presets, defaults and duration aliases share keys', function () {
    $a = ['time'=>'2027-01-15T10:00:00+02:00','tz'=>'Asia/Tokyo','fontColor'=>'#FFF'];
    $b = ['time'=>'2027-01-15T08:00:00Z','width'=>640,'height'=>140,'font'=>'BebasNeue','fontColor'=>'ffffff'];
    assert_eq(cache_test_key($a), cache_test_key($b));
    assert_eq(cache_test_key(['relative'=>'120m','duration'=>30]), cache_test_key(['evergreen'=>'7200']));
    assert_eq(cache_test_key(['evergreen'=>'2h','preset'=>'unknown']), cache_test_key(['evergreen'=>'2h']));
    $preset = Presets::apply(['preset'=>'dark-boxes','evergreen'=>'2h']);
    unset($preset['preset']);
    assert_eq(cache_test_key($preset), cache_test_key(['preset'=>'dark-boxes','evergreen'=>'120m']));
});

test('Expired keys discard deadlines but preserve actual frame counts', function () {
    $a = ['time'=>'2020-01-01','seconds'=>30];
    assert_eq(cache_test_key($a), cache_test_key(['time'=>'2021-06-03','tz'=>'Europe/Warsaw','seconds'=>120]));
    assert_true(cache_test_key($a) !== cache_test_key(['evergreen'=>'0s','seconds'=>2]));
    assert_true(cache_test_key(['evergreen'=>'0s','seconds'=>1]) !== cache_test_key(['evergreen'=>'0s','seconds'=>2]));
    assert_eq(cache_test_key($a), cache_test_key(['evergreen'=>'invalid']));
    assert_true(str_contains(cache_test_key($a), '/v2/expired/'));
});

test('Only unused appearance fields are discarded', function () {
    $p = ['evergreen'=>'2h'];
    assert_eq(cache_test_key($p), cache_test_key($p+['boxBg'=>'123456','boxBgEnd'=>'654321','boxBorder'=>'ff0000','boxRadius'=>20,'boxPadding'=>20,'bgFit'=>'contain']));
    foreach (['fontColor'=>'123456','font'=>'Inter-Bold','width'=>641,'height'=>141,'separator'=>'-','xOffset'=>1,'yOffset'=>0,'transparent'=>true,'fontSize'=>20,'labelColor'=>'123456','sepColor'=>'123456'] as $field=>$value) {
        assert_true(cache_test_key($p) !== cache_test_key($p+[$field=>$value]), $field);
    }
    $p += ['boxStyle'=>'gradient'];
    foreach (['boxBg'=>'123456','boxBgEnd'=>'654321','boxRadius'=>20,'boxPadding'=>20] as $field=>$value) {
        assert_true(cache_test_key($p) !== cache_test_key($p+[$field=>$value]), $field);
    }
    foreach (['https://images.example.com/a?x=1','https://images.example.com/a?x=2','https://images.example.com:8443/a?x=1','https://images.example.com/b?x=1'] as $url) $keys[] = cache_test_key($p+['bgImage'=>$url]);
    assert_eq(4,count(array_unique($keys)));
});

test('Active buckets coalesce evergreen requests without rounding absolute deadlines', function () {
    $p=['evergreen'=>'2h','seconds'=>30];
    assert_eq(cache_test_key($p,1800000000),cache_test_key($p,1800000029));
    assert_true(cache_test_key($p,1800000029) !== cache_test_key($p,1800000030));
    assert_true(cache_test_key(['time'=>'2027-01-15T08:00:31Z']) !== cache_test_key(['time'=>'2027-01-15T08:00:32Z']));
});

test('Many variants and buckets use at most 64 stable lock inodes', function () {
    $dir=tmp_dir('cache-stripes');
    try {
        $cm=new CacheManager($dir);
        for($i=0;$i<320;$i++) {
            $path=$cm->computeKey(new RenderSpec(['evergreen'=>'2h','width'=>100+$i%200],1800000000+$i*30));
            $fh=$cm->acquireLock($path); assert_true(is_resource($fh)); $cm->releaseLock($fh);
        }
        $all=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS));
        $locks=[];
        foreach($all as $file) if (str_ends_with($file->getFilename(),'.lock')) $locks[]=$file->getPathname();
        assert_true(count($locks)>0 && count($locks)<=64,'320 variants/buckets created '.count($locks).' lock files');
        foreach($locks as $lock) assert_true(!str_ends_with($lock,'.gif.lock'));
    } finally { rm_rf($dir); }
});

test('Write failures are explicit and leave no own temp or partial final', function () {
    $dir=tmp_dir('cache-io'); $cm=new CacheManager($dir);
    try {
        file_put_contents($dir.'/file','collision');
        foreach ([$dir.'/file/a.gif',$dir.'/target.gif'] as $path) {
            if ($path === $dir.'/target.gif') mkdir($path);
            $failed=false;
            try { $cm->write($path,'GIF89a'.str_repeat('x',2048).';'); } catch (RuntimeException) { $failed=true; }
            assert_true($failed,'write must report failure');
            assert_eq([],glob($path.'.tmp.*'));
            assert_false(is_file($path));
        }
    } finally { rm_rf($dir); }
});

test('Cleanup retains held lock inode and serializes the next process', function () {
    assert_true(function_exists('pcntl_fork'),'pcntl required');
    $dir=tmp_dir('cache-clean-lock'); $cm=new CacheManager($dir);
    try {
        $path=$cm->computeKey(['evergreen'=>'0s']);
        $held=$cm->acquireLock($path); assert_true(is_resource($held));
        $stat=fstat($held);
        if (!is_dir(dirname($path))) mkdir(dirname($path),0755,true);
        file_put_contents($path,'old'); touch($path,time()-90000);
        file_put_contents($path.'.tmp.stale','old'); touch($path.'.tmp.stale',time()-90000);
        CacheManager::cleanup($dir);
        assert_true(file_exists($path),'held stripe protects GIF');
        assert_true(file_exists($path.'.tmp.stale'),'held stripe protects temp');
        $pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,STREAM_IPPROTO_IP);
        $pid=pcntl_fork();
        if ($pid===0) {
            fclose($pair[0]); fclose($held);
            fwrite($pair[1],'ready');
            $next=$cm->acquireLock($path); $nextStat=fstat($next);
            fwrite($pair[1],(string)$nextStat['ino']); $cm->releaseLock($next); exit(0);
        }
        fclose($pair[1]); assert_eq('ready',fread($pair[0],5));
        $read=[$pair[0]];$write=$except=[];
        assert_eq(0,stream_select($read,$write,$except,0,150000),'peer must wait for held inode');
        $cm->releaseLock($held);
        assert_eq((string)$stat['ino'],stream_get_contents($pair[0])); fclose($pair[0]);
        pcntl_waitpid($pid,$status); assert_eq(0,pcntl_wexitstatus($status));
        CacheManager::cleanup($dir);
        assert_false(file_exists($path)); assert_false(file_exists($path.'.tmp.stale'));
        $next=$cm->acquireLock($path); assert_eq($stat['ino'],fstat($next)['ino']);$cm->releaseLock($next);
    } finally { rm_rf($dir); }
});

test('Retention covers every partition and preserves deadline and counter inodes', function () {
    $dir=tmp_dir('cache-retain');
    try {
        foreach (['v2/ab','v2/ev','v2/expired','ab','ev','expired'] as $partition) {
            $base=$dir.'/'.$partition.'/aa'; mkdir($base,0755,true);
            foreach (['old.gif','fresh.gif','old.gif.tmp.123','fresh.gif.tmp.123','old.gif.lock'] as $name) {
                file_put_contents($base.'/'.$name,'fixture');
                touch($base.'/'.$name,str_starts_with($name,'old')?time()-90000:time());
            }
        }
        foreach (['uid/legacy.deadline','uid/.locks/00.lock','ratelimit/a','apikeys/a'] as $name) {
            @mkdir(dirname($dir.'/'.$name),0755,true);file_put_contents($dir.'/'.$name,'1');touch($dir.'/'.$name,1);
            $inodes[$name]=fileinode($dir.'/'.$name);
        }
        CacheManager::cleanup($dir);
        foreach (['v2/ab','v2/ev','v2/expired','ab','ev','expired'] as $partition) {
            $base=$dir.'/'.$partition.'/aa';
            assert_false(file_exists($base.'/old.gif'));
            assert_false(file_exists($base.'/old.gif.tmp.123'));
            assert_true(file_exists($base.'/fresh.gif'));assert_true(file_exists($base.'/fresh.gif.tmp.123'));
            assert_true(file_exists($base.'/old.gif.lock'),'legacy locks need offline migration');
        }
        foreach($inodes as $name=>$ino) assert_eq($ino,fileinode($dir.'/'.$name),$name);
    } finally { rm_rf($dir); }
});

test('Custom retention skips legacy writers and never replaces their locks', function () {
    $dir=tmp_dir('cache-legacy');
    try {
        $path=$dir.'/ab/aa/legacy.gif';mkdir(dirname($path),0755,true);
        $legacy=fopen($path.'.lock','c');flock($legacy,LOCK_EX);
        $inode=fstat($legacy)['ino'];
        foreach ([$path,$path.'.tmp.123'] as $file) { file_put_contents($file,'old');touch($file,time()-300); }
        assert_eq(0,CacheManager::cleanup($dir,60,120));
        assert_true(file_exists($path));assert_true(file_exists($path.'.tmp.123'));
        flock($legacy,LOCK_UN);fclose($legacy);
        assert_eq(2,CacheManager::cleanup($dir,60,120));
        assert_eq($inode,fileinode($path.'.lock'));
        foreach ([0,-1] as $ttl) {
            $failed=false;try { CacheManager::cleanup($dir,$ttl); }catch(InvalidArgumentException) { $failed=true; }
            assert_true($failed);
        }
    } finally { rm_rf($dir); }
});
summary_and_exit();
