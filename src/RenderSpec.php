<?php
declare(strict_types=1);
require_once __DIR__ . '/Presets.php';

/** Bounded rendering settings and one captured server time. */
final class RenderSpec
{
    public static ?Closure $clock = null;
    public readonly array $params;
    public readonly int $now;
    public readonly int $reference;
    public readonly int $target;
    public readonly int $bucket;
    public readonly bool $evergreen;
    private const FIELDS = ['time','evergreen','relative','width','height','seconds','duration','delay',
        'boxColor','fontColor','font','fontSize','xOffset','yOffset','tz','bgImage','bgFit',
        'transparent','preset','boxStyle','boxBg','boxBgEnd','boxBorder','boxRadius','boxPadding',
        'separator','sepColor','labelColor'];

    public static function now(): int { return self::$clock ? (self::$clock)() : time(); }

    public static function validateQuery(array $query, bool $preview): void
    {
        foreach ($query as $value) {
            if (is_string($value) && preg_match('//u', $value) !== 1) {
                throw new InvalidArgumentException('Invalid parameter encoding');
            }
        }
        foreach (array_merge(self::FIELDS, ['key','_preview'], $preview ? [] : ['uid','uidCampaign']) as $field) {
            if (isset($query[$field]) && !is_scalar($query[$field])) {
                if ($preview && $field === 'key') continue;
                throw new InvalidArgumentException('Invalid parameter type');
            }
        }
    }

    public static function normalize(array $input): array
    {
        self::validateQuery($input, false);
        $p = array_intersect_key($input, array_flip(self::FIELDS));
        $p['evergreen'] = $p['evergreen'] ?? $p['relative'] ?? null;
        $p['seconds'] = $p['seconds'] ?? $p['duration'] ?? null;
        unset($p['relative'], $p['duration']);
        $p = Presets::apply($p);
        if (($input['separator'] ?? null) === '') $p['separator'] = '';
        $p = array_filter($p, static fn($value) => $value !== null);
        $bound = static fn($value, int $default, int $min, int $max): int =>
            max($min, min($max, is_numeric($value) ? (int)max($min, min($max, (float)$value)) : $default));
        $p['width'] = $bound($p['width'] ?? null, 640, 100, 1200);
        $p['height'] = $bound($p['height'] ?? null, 140, 40, 400);
        $p['seconds'] = $bound($p['seconds'] ?? null, 30, 1, 120);
        $p['delay'] = 100;
        $p['fontSize'] = $bound($p['fontSize'] ?? null, 0, 0, min($p['height'], $p['width']));
        $p['xOffset'] = $bound($p['xOffset'] ?? null, 0, -$p['width'], $p['width']);
        if (isset($p['yOffset'])) $p['yOffset'] = $bound($p['yOffset'], 0, -$p['height'], $p['height']);
        $p['boxPadding'] = $bound($p['boxPadding'] ?? null, 12, 0, min(50, intdiv($p['height'], 4), intdiv($p['width'], 16)));
        $p['boxRadius'] = $bound($p['boxRadius'] ?? null, 10, 0, intdiv($p['height'], 2));
        $p['separator'] = in_array($p['separator'] ?? ':', [':','-',''], true) ? ($p['separator'] ?? ':') : ':';
        foreach (['boxStyle'=>['none','rounded','gradient','outline'], 'bgFit'=>['cover','stretch','contain']] as $field=>$values) {
            if (!in_array($p[$field] ?? null, $values, true)) $p[$field] = $values[0];
        }
        $p['font'] = in_array($p['font'] ?? '', ['BebasNeue','Inter-Bold','Montserrat-Bold'], true) ? $p['font'] : 'BebasNeue';
        $p['transparent'] = !empty($p['transparent']);
        foreach (['boxColor'=>'000000','fontColor'=>'ffffff','boxBg'=>'2d2d4a',
            'boxBgEnd'=>'24243e','boxBorder'=>'dddddd','labelColor'=>$p['fontColor'] ?? 'ffffff',
            'sepColor'=>$p['fontColor'] ?? 'ffffff'] as $field=>$default) {
            if ($field === 'labelColor' || $field === 'sepColor') $default = $p['fontColor'];
            $value = ltrim((string)($p[$field] ?? $default), '#');
            if (!preg_match('/^(?:[a-f0-9]{3}|[a-f0-9]{6})$/iD', $value)) $value = $default;
            if (strlen($value) === 3) $value = $value[0].$value[0].$value[1].$value[1].$value[2].$value[2];
            $p[$field] = strtolower($value);
        }
        foreach (['time'=>128,'evergreen'=>128,'tz'=>64,'bgImage'=>4096] as $field=>$limit) {
            if (isset($p[$field])) {
                $p[$field] = (string)$p[$field];
                if (strlen($p[$field]) > $limit) throw new InvalidArgumentException('Parameter too long');
            }
        }
        return $p;
    }

    public function __construct(array $settings, ?int $now = null)
    {
        $this->params = self::normalize($settings);
        $this->now = $now ?? self::now();
        $this->reference = $this->now;
        $this->bucket = intdiv($this->now, $this->params['seconds']) * $this->params['seconds'];
        $this->evergreen = !empty($this->params['evergreen']);
        $this->target = $this->evergreen
            ? $this->now + self::duration($this->params['evergreen'])
            : self::absolute($this->params['time'] ?? 'now', $this->params['tz'] ?? null,
                $this->now, $this->now + $this->params['seconds']);
    }

    public function cacheIdentity(): array
    {
        $p = $this->params;
        unset($p['preset'], $p['time'], $p['tz'], $p['evergreen']);
        if ($p['boxStyle'] === 'none') {
            unset($p['boxBg'], $p['boxBgEnd'], $p['boxBorder'], $p['boxRadius'], $p['boxPadding']);
        } else {
            if ($p['boxStyle'] !== 'gradient') unset($p['boxBgEnd']);
            if ($p['boxStyle'] !== 'outline') unset($p['boxBorder']);
        }
        if ($p['separator'] === '') unset($p['sepColor']);
        if ($p['transparent']) {
            unset($p['boxColor'], $p['bgImage'], $p['bgFit']);
        } elseif (empty($p['bgImage'])) {
            unset($p['bgImage'], $p['bgFit']);
        }
        if ($this->target <= $this->now) {
            $p['seconds'] = min(3, $p['seconds']);
            $p['_expired'] = true;
        } else {
            $p['_bucket'] = $this->bucket;
            $p[$this->evergreen ? '_duration' : '_target'] = $this->evergreen
                ? $this->target - $this->now : $this->target;
        }
        ksort($p);
        return $p;
    }

    /** Duration in seconds: unsigned seconds or complete d/h/m/s unit sequences, at most 1000 years. */
    public static function duration(string $spec): int
    {
        $s = strtolower(trim($spec));
        if (strlen($s) > 128) return 0;
        $limit = 31536000000;
        if (preg_match('/^\d+$/D', $s)) return (float)$s <= $limit ? (int)$s : 0;
        $unit = '(?:days?|d|hours?|hrs?|h|minutes?|mins?|m|seconds?|secs?|s)';
        if (!preg_match('/^(?:\d+\s*'.$unit.'\s*)+$/D', $s)) return 0;
        preg_match_all('/(\d+)\s*('.$unit.')/', $s, $matches, PREG_SET_ORDER);
        $total = 0;
        foreach ($matches as $match) {
            $multiplier = ['d'=>86400,'h'=>3600,'m'=>60,'s'=>1][$match[2][0]];
            if ((float)$match[1] > intdiv($limit - $total, $multiplier)) return 0;
            $total += (int)$match[1] * $multiplier;
        }
        return $total;
    }

    public static function absolute(string $time, ?string $tz, int $now, int $fallback): int
    {
        try { $zone = new DateTimeZone($tz ?: 'UTC'); }
        catch (Throwable) { $zone = new DateTimeZone('UTC'); }
        try {
            if (strlen($time) > 128) return $fallback;
            if (trim($time) === '') return $now;
            $parsed = date_parse($time);
            if ($parsed['error_count'] > 0) return $fallback;
            if (isset($parsed['zone_type'])) {
                $zone = match ($parsed['zone_type']) {
                    1 => new DateTimeZone(sprintf('%s%02d:%02d', $parsed['zone'] < 0 ? '-' : '+',
                        intdiv(abs($parsed['zone']),3600), intdiv(abs($parsed['zone'])%3600,60))),
                    2 => new DateTimeZone($parsed['tz_abbr']),
                    3 => new DateTimeZone($parsed['tz_id']),
                };
            }
            $base = (new DateTimeImmutable('@'.$now))->setTimezone($zone);
            $date = @$base->modify($time);
            if ($parsed['hour'] === false && ($parsed['year'] !== false
                || $parsed['month'] !== false || $parsed['day'] !== false)) $date = $date->setTime(0,0);
            $target = $date->getTimestamp();
            return $target >= -62135596800 && $target <= 253402300799 ? $target : $fallback;
        } catch (Throwable) { return $fallback; }
    }
}
