import json
import os
import unittest
from PIL import ImageChops
from harness import Sandbox, decode

class RenderSpecContracts(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.s = Sandbox()
        cls.addClassCleanup(cls.s.close)

    def setUp(self):
        self.s.reset()

    def direct(self, name, params, now=1800000029):
        code = "require 'src/CountdownTimer.php'; if (class_exists('RenderSpec')) RenderSpec::$clock = fn() => (int)$argv[3]; file_put_contents($argv[1], (new CountdownTimer())->generate(json_decode($argv[2], true)));"
        out = self.s.root / (name + '.gif')
        self.s.php(code, out, json.dumps(params), now)
        return decode(out.read_bytes())

    def test_direct_bounds_and_scalar(self):
        frames, delays, _, _ = self.direct('bounds', {'evergreen':'2h','width':1400,'height':500,'seconds':1,'fontSize':500,'separator':'x'*64})
        self.assertEqual((1200,400), frames[0].size)
        self.assertEqual([1000], delays)
        p = {'evergreen':'2h','seconds':1,'fontSize':500,'separator':':' ,'width':1200,'height':400}
        ref = self.direct('bounded-reference',p)[0][0]
        self.assertEqual(ref.tobytes(), frames[0].tobytes())

    def test_font_and_separator_bounds_separately(self):
        p={'evergreen':'2h','width':640,'height':140,'seconds':1}
        large=self.direct('large-font',{**p,'fontSize':500})[0][0]
        bounded=self.direct('bounded-font',{**p,'fontSize':140})[0][0]
        self.assertEqual(bounded.tobytes(),large.tobytes())
        long=self.direct('long-separator',{**p,'separator':'x'*64})[0][0]
        colon=self.direct('colon-separator',{**p,'separator':':'})[0][0]
        self.assertEqual(colon.tobytes(),long.tobytes())

    def test_x_translation_all_content(self):
        for style in ('none','rounded','outline','gradient'):
            p = {'preset':'dark-boxes','evergreen':'2h','seconds':1,'fontSize':20,'boxStyle':style,'boxColor':'000000'}
            a = self.direct('x0-'+style,p)[0][0]
            b = self.direct('x20-'+style,{**p,'xOffset':20})[0][0]
            self.assertEqual(a.crop((0,0,620,140)).tobytes(), b.crop((20,0,640,140)).tobytes())
            self.assertIsNotNone(ImageChops.difference(a.convert('RGB'), b.convert('RGB')).getbbox())

    def test_large_days_remain_inside_boxes(self):
        p = {'preset':'dark-boxes','evergreen':'1000d','seconds':1,'boxBg':'ff0000','boxColor':'000000','boxRadius':0,'fontColor':'ffffff'}
        frame = self.direct('large-days',p)[0][0].convert('RGB')
        red = lambda c: c[0]>150 and c[1]<50 and c[2]<50
        white = lambda c: min(c)>200
        red_x = [x for x in range(frame.width) if any(red(frame.getpixel((x,y))) for y in range(frame.height))]
        self.assertTrue(red_x, 'day layout must retain visible boxes')
        start, end = min(red_x), max(red_x)
        first_end = next(x for x in range(start,end) if not any(red(frame.getpixel((x,y))) for y in range(frame.height)))
        # Every bright pixel at digit height in the first group lies inside its box.
        first_center = (start+first_end)//2
        ys = [y for y in range(frame.height) if red(frame.getpixel((first_center,y)))]
        self.assertTrue(ys)
        self.assertTrue(any(white(frame.getpixel((x,y))) for x in range(start,first_end) for y in ys))
        self.assertFalse(any(white(frame.getpixel((x,y))) for x in range(max(0,start-10),start) for y in ys))

    def test_fixed_clock_http_deadline_and_ttl(self):
        s = Sandbox()
        try:
            index = s.app/'index.php'
            source = index.read_text()
            marker = "require_once __DIR__ . '/src/Presets.php';"
            index.write_text(source.replace(marker, marker + "\nrequire_once __DIR__ . '/src/RenderSpec.php'; RenderSpec::$clock = fn() => (int)file_get_contents(__DIR__ . '/clock.txt');"))
            clock = s.app/'clock.txt'
            clock.write_text('1800000029')
            s.seed_uid('fixed', 1800000031)
            s.start()
            p = {'key':'test-main','preset':'dark-boxes','time':'2027-01-15T08:00:31Z','seconds':30}
            for now, ttl in ((1800000029,1),(1800000030,1),(1800000031,86400)):
                clock.write_text(str(now))
                status,hdr,body=s.request('clock-'+str(now),p)
                self.assertEqual(200,status)
                self.assertIn('max-age='+str(ttl), hdr['cache-control'])
                ref=self.direct('clock-ref-'+str(now),{'preset':'dark-boxes','evergreen':str(1800000031-now)+'s','seconds':30},now)[0]
                actual=decode(body)[0]
                self.assertEqual([f.tobytes() for f in ref],[f.tobytes() for f in actual])
                self.assertEqual(1800000031-now+3, len(actual))
                uid = s.request('clock-uid-'+str(now), {'key':'test-main','preset':'dark-boxes','evergreen':'2h','uid':'fixed','seconds':30})
                self.assertEqual(200,uid[0])
                self.assertEqual(body,uid[2])
                # Align fixture filesystem timestamps with the injected server clock.
                for gif in s.runtime.rglob('*.gif'): os.utime(gif,(now,now))
                hit = s.request('clock-hit-'+str(now), p)
                self.assertEqual('HIT',hit[1]['x-cache'])
                self.assertEqual(body,hit[2])
                cached = s.request('clock-304-'+str(now), p, {'If-None-Match':hdr['etag']})
                self.assertEqual(304,cached[0])
                self.assertIn('max-age='+str(ttl),cached[1]['cache-control'])
            # Query fields cannot supply the server clock or parsed target.
            s.reset()
            status,hdr,body=s.request('clock-query',{**p,'now':'0','target':'0','bucket':'0'})
            self.assertEqual(200,status)
            self.assertIn('immutable',hdr['cache-control'])
        finally:
            s.close()


    def test_deadline_boundary_has_no_negative_or_extra_group(self):
        p = {'preset':'dark-boxes','seconds':30,'boxRadius':0,'boxColor':'000000','boxBg':'ff0000'}
        now = 1800000029
        def box_runs(frame):
            rgb = frame.convert('RGB')
            red = lambda c: c[0]>150 and c[1]<50 and c[2]<50
            cols = [any(red(rgb.getpixel((x,y))) for y in range(rgb.height)) for x in range(rgb.width)]
            return sum(1 for i,c in enumerate(cols) if c and (i==0 or not cols[i-1]))
        at, delays, info, _ = self.direct('deadline-now', {**p,'time':'2027-01-15T08:00:29Z'}, now)
        self.assertEqual(3, len(at))
        self.assertEqual([1000]*3, delays)
        self.assertEqual(1, info['loop'])
        self.assertEqual(at[0].tobytes(), at[2].tobytes())
        self.assertEqual(3, box_runs(at[0]), 'hours/minutes/seconds only, no day group')
        past = self.direct('deadline-past', {**p,'time':'2027-01-14T08:00:29Z'}, now)[0]
        self.assertEqual(at[0].tobytes(), past[0].tobytes(), 'a past deadline never shows negative time')
        ending = self.direct('deadline-soon', {**p,'time':'2027-01-15T08:00:31Z'}, now)[0]
        self.assertEqual(5, len(ending))
        self.assertNotEqual(at[0].tobytes(), ending[0].tobytes())
        self.assertEqual(at[0].tobytes(), ending[2].tobytes())
        self.assertEqual(at[0].tobytes(), ending[4].tobytes())

    def test_transparent_disposal_and_end(self):
        p = {'preset':'transparent','time':'2027-01-15T08:00:40Z','seconds':12}
        frames, delays, info, disposal = self.direct('alpha-animation',p,1800000029)
        self.assertEqual([1000]*12,delays)
        self.assertEqual([2]*12,disposal)
        for i,frame in enumerate(frames):
            self.assertEqual(0,frame.getpixel((0,0))[3])
            self.assertFalse(any(a==255 and (r,g,b)==(255,0,255) for r,g,b,a in frame.getdata()),
                             'no opaque chroma-key pixels survive in a decoded frame')
            self.assertGreater(sum(a==255 for a in frame.getchannel('A').getdata()), 100)
            reference=self.direct('alpha-ref-'+str(i),{**p,'seconds':1},1800000029+i)[0][0]
            self.assertEqual(reference.tobytes(),frame.tobytes(), 'each frame clears the previous digits')
        expired=self.direct('alpha-end',p,1800000040)
        self.assertEqual(1,expired[2]['loop'])
        self.assertEqual(3,len(expired[0]))
        self.assertEqual(frames[-1].tobytes(),expired[0][0].tobytes())

    def test_small_geometry_and_reused_renderer(self):
        for preset in ('dark-boxes','gradient-cards','minimal-light','bold-color','transparent'):
            frames,_,_,_=self.direct('small-'+preset,{'preset':preset,'evergreen':'1000d','width':100,'height':40,'fontSize':100000,'boxPadding':100000,'boxRadius':100000,'seconds':1})
            self.assertEqual((100,40),frames[0].size)
            frame=frames[0]
            corner=frame.getpixel((0,0))
            border=[frame.getpixel((x,y)) for x in range(100) for y in (0,39)]
            border += [frame.getpixel((x,y)) for x in (0,99) for y in range(40)]
            self.assertTrue(all(pixel==corner for pixel in border), 'bounded layout stays inside the canvas')
            self.assertGreater(len(set(frame.getdata())),1)
        code = "require 'src/CountdownTimer.php'; $t=new CountdownTimer; $a=['preset'=>'dark-boxes','evergreen'=>'2h','seconds'=>1]; $b=['preset'=>'transparent','evergreen'=>'0s','seconds'=>3]; $t->generate($a); echo hash('sha256',$t->generate($b))===hash('sha256',(new CountdownTimer)->generate($b)) ? 'same' : 'different';"
        self.assertEqual('same',self.s.php(code))

    def http(self):
        s=Sandbox()
        self.addCleanup(s.close)
        s.start()
        return s

    def test_http_all_known_array_fields(self):
        s=self.http()
        fields=('time evergreen relative width height seconds duration delay boxColor fontColor font fontSize '
                'xOffset yOffset tz bgImage bgFit transparent preset boxStyle boxBg boxBgEnd boxBorder '
                'boxRadius boxPadding separator sepColor labelColor key _preview uid uidCampaign').split()
        for field in fields:
            with self.subTest(field=field):
                s.reset()
                p={'key':'test-main','evergreen':'2h',field+'[]':'x'}
                p.pop(field,None)
                status,hdr,body=s.request('array-'+field,p)
                self.assertEqual(400,status)
                self.assertIn('application/json',hdr['content-type'])
                self.assertTrue(json.loads(body)['error'])
        status,_,body=s.request('ignored-arrays',{'key[]':'ignored','_preview':'1','evergreen':'2h','uid[]':'ignored','uidCampaign[]':'ignored','campaign[]':'ignored','unknown[]':'ignored'})
        self.assertEqual(200,status)
        self.assertFalse((s.runtime/'uid').exists())
        self.assertEqual((480,140),decode(body)[0][0].size)

    def test_http_bounds_and_fallbacks(self):
        s=self.http()
        p={'key':'test-caps','evergreen':'2h','width':'1e99','height':'1e99','seconds':'1e99','fontSize':'1e99',
           'boxPadding':'1e99','boxRadius':'1e99','separator':'x'*256,'font':'invalid','boxStyle':'invalid','bgFit':'invalid',
           'boxColor':'invalid','fontColor':'invalid','sepColor':'invalid','labelColor':'invalid','delay':'0'}
        status,_,body=s.request('bounded-http',p)
        self.assertEqual(200,status)
        frames,delays,_,_=decode(body)
        self.assertEqual((320,80),frames[0].size)
        self.assertEqual([1000]*4,delays)
        ref=self.direct('bounded-http-reference',{'evergreen':'2h','width':320,'height':80,'seconds':4,
            'fontSize':80,'boxPadding':20,'boxRadius':40,'separator':':','font':'BebasNeue',
            'boxStyle':'none','bgFit':'cover','boxColor':'000000','fontColor':'ffffff'})[0]
        self.assertEqual([f.tobytes() for f in ref],[f.tobytes() for f in frames])
        for field in ('time','evergreen','tz','bgImage'):
            status,hdr,body=s.request('long-'+field,{'key':'test-main','evergreen':'2h',field:'x'*4097})
            self.assertEqual(400,status)
            self.assertTrue(json.loads(body)['error'])

    def test_http_five_presets_and_layout_rules(self):
        s=self.http()
        for preset in ('dark-boxes','gradient-cards','minimal-light','bold-color','transparent'):
            p={'preset':preset,'evergreen':'86401s','seconds':4,'delay':50,'xOffset':10}
            status,_,body=s.request('preset-'+preset,{'key':'test-main',**p})
            self.assertEqual(200,status)
            frames,delays,info,disposal=decode(body)
            ref=self.direct('preset-ref-'+preset,p)[0]
            self.assertEqual([f.tobytes() for f in ref],[f.tobytes() for f in frames])
            self.assertEqual([1000]*4,delays)
            self.assertEqual(0,info['loop'])
            self.assertEqual([2]*4,disposal)
            if preset=='transparent':
                self.assertTrue(all(f.getpixel((0,0))[3]==0 for f in frames))
        p={'preset':'dark-boxes','evergreen':'2h','seconds':1,'fontSize':20,'boxColor':'000000'}
        _,_,body=s.request('offset-zero',{'key':'test-main',**p})
        _,_,shift=s.request('offset-shift',{'key':'test-main',**p,'xOffset':20})
        self.assertEqual(decode(body)[0][0].crop((0,0,620,140)).tobytes(),decode(shift)[0][0].crop((20,0,640,140)).tobytes())

    def test_http_separator_alias_and_duration_contract(self):
        s=self.http()
        p={'key':'test-main','preset':'dark-boxes','relative':'120m','duration':2,'separator':''}
        status,_,body=s.request('alias-empty',p)
        self.assertEqual(200,status)
        ref=self.direct('alias-reference',{'preset':'dark-boxes','evergreen':'2 hours','seconds':2,'separator':''})[0]
        self.assertEqual([f.tobytes() for f in ref],[f.tobytes() for f in decode(body)[0]])
        for sep in (':','-','invalid'):
            status,_,data=s.request('separator-'+sep,{**p,'separator':sep})
            self.assertEqual(200,status)
            reference=self.direct('sep-ref-'+sep,{'preset':'dark-boxes','evergreen':'7200','seconds':2,'separator':sep if sep in (':','-') else ':'})[0]
            self.assertEqual(reference[0].tobytes(),decode(data)[0][0].tobytes())
            self.assertNotEqual(body,data)
        for duration in ('junk2h','9999999999999999999999999999d'):
            status,hdr,data=s.request('invalid-duration-'+str(len(duration)),{'key':'test-main','evergreen':duration})
            self.assertEqual(200,status)
            self.assertIn('immutable',hdr['cache-control'])
            self.assertEqual(3,len(decode(data)[0]))

if __name__ == '__main__':
    unittest.main()
