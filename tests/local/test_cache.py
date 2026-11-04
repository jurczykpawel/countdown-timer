import concurrent.futures
import json
import os
from pathlib import Path
import subprocess
import time
import unittest
from harness import PHP, Sandbox, decode


class CacheContracts(unittest.TestCase):
    def setUp(self):
        self.s = Sandbox()
        self.addCleanup(self.s.close)
        index = self.s.app / 'index.php'
        marker = "require_once __DIR__ . '/src/Presets.php';"
        index.write_text(index.read_text().replace(marker, marker + "\nrequire_once __DIR__ . '/src/RenderSpec.php'; RenderSpec::$clock = fn() => (int)file_get_contents(__DIR__.'/clock.txt');"))
        self.clock = self.s.app / 'clock.txt'
        self.clock.write_text('1800000029')
        self.s.start()

    def request(self, name, params=None, headers=None):
        return self.s.request(name, {'key':'test-main', 'time':'2020-01-01', **(params or {})}, headers)

    def test_canonical_http_and_decoded_end(self):
        first = self.request('end-first', {'seconds':30})
        self.assertEqual(200,first[0])
        second = self.request('end-alias', {'time':'2021-01-01T00:00:00+05:30','tz':'Asia/Tokyo','seconds':120,'fontColor':'#FFF'})
        self.assertEqual('HIT',second[1]['x-cache'])
        self.assertEqual(first[1]['etag'],second[1]['etag'])
        self.assertEqual(first[2],second[2])
        for count in (1,2,3):
            response=self.request('end-frames-'+str(count),{'seconds':count})
            frames,delays,info,_=decode(response[2])
            self.assertEqual(count,len(frames));self.assertEqual([1000]*count,delays)
            self.assertEqual(1,info['loop'])
            self.assertTrue(all(frame.tobytes()==frames[0].tobytes() for frame in frames))
            if count<3:self.assertNotEqual(first[1]['etag'],response[1]['etag'])
        # Preset spelling and unused fields do not fragment the final appearance.
        explicit={'time':'2022-01-01','font':'BebasNeue','width':640,'height':140,
                  'boxBg':'123456','boxRadius':20,'boxPadding':20,'bgFit':'contain','preset':'unknown'}
        same=self.request('end-defaults',explicit)
        self.assertEqual('HIT',same[1]['x-cache']);self.assertEqual(first[2],same[2])

    def test_active_equivalents_and_precise_deadline(self):
        first=self.request('absolute',{'time':'2027-01-15T08:02:00Z','seconds':4})
        same=self.request('offset',{'time':'2027-01-15T10:02:00+02:00','seconds':4,'tz':'Pacific/Honolulu'})
        self.assertEqual('HIT',same[1]['x-cache']);self.assertEqual(first[1]['etag'],same[1]['etag'])
        other=self.request('one-second-later',{'time':'2027-01-15T08:02:01Z','seconds':4})
        self.assertNotEqual(first[1]['etag'],other[1]['etag']);self.assertNotEqual(first[2],other[2])
        ev=self.request('evergreen',{'evergreen':'2h','seconds':4})
        alias=self.request('relative',{'relative':'120m','duration':4})
        self.assertEqual('HIT',alias[1]['x-cache']);self.assertEqual(ev[1]['etag'],alias[1]['etag'])

    def test_content_etag_after_cache_loss_and_distinct_appearance(self):
        p={'time':'2027-01-15T08:02:00Z','seconds':30}
        self.clock.write_text('1800000028')
        first=self.request('before-cache-loss',p)
        for gif in self.s.runtime.rglob('*.gif'):gif.unlink()
        self.clock.write_text('1800000029')
        second=self.request('after-cache-loss',p,{'If-None-Match':first[1]['etag']})
        self.assertEqual(200,second[0]);self.assertEqual('MISS',second[1]['x-cache'])
        self.assertNotEqual(first[2],second[2]);self.assertNotEqual(first[1]['etag'],second[1]['etag'])
        # Compare each cached variant against a fresh, independently rendered GIF.
        for i,variant in enumerate(({}, {'fontColor':'ff0000'}, {'font':'Inter-Bold'}, {'separator':'-'},
                                    {'transparent':'1'}, {'boxStyle':'rounded'}, {'xOffset':10}, {'width':480})):
            params={'time':'2020-01-01','seconds':3,**variant}
            response=self.request('variant-'+str(i),params)
            direct=self.s.root/('direct-'+str(i)+'.gif')
            self.s.php("require 'src/CountdownTimer.php'; file_put_contents($argv[1],(new CountdownTimer)->generate(new RenderSpec(json_decode($argv[2],true),1800000029)));",direct,json.dumps(params))
            actual=decode(response[2]);expected=decode(direct.read_bytes())
            self.assertEqual([f.tobytes() for f in expected[0]],[f.tobytes() for f in actual[0]])
            self.assertEqual(expected[1:3],actual[1:3])
            if not i:baseline=response
            else:self.assertNotEqual(baseline[1]['etag'],response[1]['etag'])

    def test_ttl_boundary_etag_and_conditional_miss(self):
        p={'time':'2027-01-15T08:00:31Z','seconds':30}
        etags=[]
        for now,ttl in ((1800000028,2),(1800000029,1),(1800000030,1),(1800000031,86400),(1800000032,86400)):
            self.clock.write_text(str(now))
            first=self.request('ttl-'+str(now),p);self.assertEqual(200,first[0])
            self.assertIn('max-age='+str(ttl),first[1]['cache-control']);etags.append(first[1]['etag'])
            for i,tag in enumerate((first[1]['etag'],'W/'+first[1]['etag'],'"other", W/'+first[1]['etag'],'*')):
                response=self.request('ttl-304-'+str(now)+'-'+str(i),p,{'If-None-Match':tag})
                self.assertEqual((304,b''),(response[0],response[2]));self.assertEqual('HIT-304',response[1]['x-cache'])
                self.assertEqual(first[1]['etag'],response[1]['etag']);self.assertEqual(first[1]['cache-control'],response[1]['cache-control'])
        self.assertEqual(etags[0],etags[1]);self.assertNotEqual(etags[1],etags[2]);self.assertNotEqual(etags[2],etags[3]);self.assertEqual(etags[3],etags[4])
        # No file means no conditional HIT: generation produces a 200 MISS first.
        self.s.reset()
        miss=self.request('conditional-empty',headers={'If-None-Match':'*'})
        self.assertEqual(200,miss[0]);self.assertEqual('MISS',miss[1]['x-cache']);decode(miss[2])

    def test_io_failure_then_retry_and_read_failure(self):
        for phase in ('mkdir','rename','read-empty','read-directory','lock'):
            with self.subTest(phase=phase):
                self.s.reset()
                p={'time':'2020-01-01','seconds':3}
                code="require 'src/CacheManager.php'; echo (new CacheManager($argv[1]))->computeKey(new RenderSpec(json_decode($argv[2],true),1800000029));"
                path=Path(self.s.php(code,self.s.runtime,json.dumps(p)))
                if phase=='mkdir': (self.s.runtime/path.relative_to(self.s.runtime).parts[0]).write_text('collision')
                elif phase=='lock': (self.s.runtime/'.render-locks').write_text('collision')
                else:
                    path.parent.mkdir(parents=True)
                    if phase in ('rename','read-directory'):path.mkdir()
                    else:path.write_bytes(b'')
                response=self.request('io-'+phase,{'key':'test-quota',**p})
                self.assertEqual(503,response[0]);self.assertEqual('1',response[1]['retry-after'])
                self.assertIn('application/json',response[1]['content-type']);self.assertTrue(json.loads(response[2])['error'])
                self.assertNotIn(str(self.s.runtime).encode(),response[2]);self.assertEqual([],list(self.s.runtime.rglob('*.tmp.*')))
                self.s.reset()
                recovered=self.request('io-recovered-'+phase,p);self.assertEqual(200,recovered[0]);decode(recovered[2])

    def test_real_short_write_reports_failure(self):
        code="""require 'src/CacheManager.php'; pcntl_signal(SIGXFSZ,SIG_IGN);
        posix_setrlimit(POSIX_RLIMIT_FSIZE,1024,1024);
        try { (new CacheManager($argv[1]))->write($argv[1].'/short.gif','GIF89a'.str_repeat('x',8192).';'); echo 'success'; }
        catch (RuntimeException) { echo 'unavailable'; }"""
        self.assertEqual('unavailable',self.s.php(code,self.s.runtime))
        self.assertFalse((self.s.runtime/'short.gif').exists());self.assertEqual([],list(self.s.runtime.rglob('*.tmp.*')))

    def test_endpoint_write_failure_can_consume_one_generation(self):
        runner="""http_response_code(200); register_shutdown_function(function(){fwrite(STDERR,(string)http_response_code());});
        pcntl_signal(SIGXFSZ,SIG_IGN); posix_setrlimit(POSIX_RLIMIT_FSIZE,1024,1024);
        $_GET=['key'=>'test-quota','time'=>'2020-01-01','seconds'=>3]; require 'index.php';"""
        result=subprocess.run(PHP+['-r',runner],cwd=self.s.app,capture_output=True,timeout=15)
        self.assertEqual(0,result.returncode);self.assertEqual(b'503',result.stderr)
        self.assertTrue(json.loads(result.stdout)['error'])
        counters=list((self.s.runtime/'apikeys').rglob('*.count'))
        self.assertEqual(['1'],[path.read_text().strip() for path in counters])
        self.assertEqual([],list(self.s.runtime.rglob('*.tmp.*')));self.assertEqual([],list(self.s.runtime.rglob('*.gif')))
        retry=self.request('after-write-error',{'key':'test-quota','seconds':3})
        self.assertEqual(429,retry[0])

    def test_singleflight_quota_and_lock_timeout(self):
        # Independent CLI request processes exercise the actual endpoint pipeline concurrently.
        p={'key':'test-quota','time':'2020-01-01','seconds':3}
        runner="http_response_code(200); register_shutdown_function(function(){fwrite(STDERR,json_encode(['status'=>http_response_code()]));}); $_GET=json_decode($argv[1],true); require 'index.php';"
        def run(i):
            r=subprocess.run(PHP+['-r',runner,'--',json.dumps(p)],cwd=self.s.app,capture_output=True,timeout=15)
            (self.s.root/f'concurrent-{i}.gif').write_bytes(r.stdout)
            (self.s.root/f'concurrent-{i}.status').write_bytes(r.stderr)
            return r
        with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:results=list(pool.map(run,range(8)))
        for r in results:
            self.assertEqual(0,r.returncode);self.assertIn(b'"status":200',r.stderr);decode(r.stdout)
        self.assertEqual(1,len({r.stdout for r in results}))
        counters=list((self.s.runtime/'apikeys').rglob('*.count'))
        self.assertEqual(1,len(counters));self.assertEqual('1',counters[0].read_text().strip())
        hit=self.request('quota-hit',p,{'If-None-Match':'*'});self.assertEqual(304,hit[0])
        self.assertEqual('1',counters[0].read_text().strip())
        self.s.reset()
        holder="require 'src/CacheManager.php'; $cm=new CacheManager($argv[1]); $path=$cm->computeKey(new RenderSpec(json_decode($argv[2],true),1800000029)); $fh=$cm->acquireLock($path); echo 'ready'; fflush(STDOUT); fread(STDIN,1); $cm->releaseLock($fh);"
        proc=subprocess.Popen(PHP+['-r',holder,'--',str(self.s.runtime),json.dumps(p)],cwd=self.s.app,stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        try:
            self.assertEqual(b'ready',proc.stdout.read(5))
            start=time.monotonic();response=self.request('timeout',p);elapsed=time.monotonic()-start
            self.assertEqual(503,response[0]);self.assertEqual('DEGRADED-FAIL',response[1]['x-lock'])
            self.assertGreaterEqual(elapsed,4.8);self.assertLess(elapsed,7)
            self.assertEqual([],list((self.s.runtime/'apikeys').rglob('*.count')))
        finally:
            proc.communicate(b'X',timeout=5)
        self.assertEqual(200,self.request('after-timeout',p)[0])


if __name__=='__main__': unittest.main()
