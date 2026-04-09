<?php
declare(strict_types=1);

/**
 * Named visual presets for countdown timers.
 * Each preset defines defaults that can be overridden by GET params.
 */
final class Presets
{
    private const PRESETS = [
        'dark-boxes' => [
            'boxColor'    => '1a1a2e',
            'fontColor'   => 'ffffff',
            'font'        => 'Montserrat-Bold',
            'width'       => 640,
            'height'      => 140,
            'boxStyle'    => 'rounded',    // rounded | none
            'boxBg'       => '2d2d4a',
            'boxRadius'   => 10,
            'boxPadding'  => 12,
            'separator'   => ':',
            'sepColor'    => '6c6c8a',
            'labelColor'  => '8888aa',
        ],
        'gradient-cards' => [
            'boxColor'    => '0f0c29',
            'fontColor'   => 'ffffff',
            'font'        => 'BebasNeue',
            'width'       => 640,
            'height'      => 140,
            'boxStyle'    => 'gradient',   // gradient fill
            'boxBg'       => '302b63',
            'boxBgEnd'    => '24243e',
            'boxRadius'   => 8,
            'boxPadding'  => 14,
            'separator'   => ':',
            'sepColor'    => '5a5a8a',
            'labelColor'  => 'aaaacc',
        ],
        'minimal-light' => [
            'boxColor'    => 'f5f5f5',
            'fontColor'   => '333333',
            'font'        => 'Inter-Bold',
            'width'       => 640,
            'height'      => 130,
            'boxStyle'    => 'outline',    // outline border only
            'boxBg'       => 'ffffff',
            'boxBorder'   => 'dddddd',
            'boxRadius'   => 6,
            'boxPadding'  => 10,
            'separator'   => ':',
            'sepColor'    => '999999',
            'labelColor'  => '888888',
        ],
        'bold-color' => [
            'boxColor'    => 'e63946',
            'fontColor'   => 'ffffff',
            'font'        => 'Montserrat-Bold',
            'width'       => 640,
            'height'      => 120,
            'boxStyle'    => 'none',
            'separator'   => ':',
            'sepColor'    => 'ffccd5',
            'labelColor'  => 'ffccd5',
        ],
        'transparent' => [
            'boxColor'    => '000000',
            'fontColor'   => 'ffffff',
            'font'        => 'BebasNeue',
            'width'       => 640,
            'height'      => 120,
            'transparent' => true,
            'boxStyle'    => 'none',
            'separator'   => ':',
            'sepColor'    => 'cccccc',
            'labelColor'  => 'cccccc',
        ],
    ];

    /**
     * Merge preset defaults with user-provided params.
     * User params always win over preset defaults.
     */
    public static function apply(array $userParams): array
    {
        $presetName = $userParams['preset'] ?? null;
        if ($presetName === null || !isset(self::PRESETS[$presetName])) {
            return $userParams;
        }

        $defaults = self::PRESETS[$presetName];
        // User params override preset defaults
        return array_merge($defaults, array_filter($userParams, fn($v) => $v !== null && $v !== ''));
    }

    public static function list(): array
    {
        return array_keys(self::PRESETS);
    }

    public static function exists(string $name): bool
    {
        return isset(self::PRESETS[$name]);
    }
}
