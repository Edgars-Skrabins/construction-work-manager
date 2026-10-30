<?php

declare(strict_types=1);

namespace App\Web\Shared\Layout\Main;

use Yiisoft\Assets\AssetBundle;

final class CalendarAsset extends AssetBundle
{
    public ?string $basePath = '@assets/calendar';
    public ?string $baseUrl = '@assetsUrl/calendar';
    public ?string $sourcePath = '@assetsSource/calendar';

    /** @var list<string> */
    public array $css = [
        'calendar.css',
    ];

    /** @var list<string> */
    public array $js = [
        'calendar.js',
    ];

    /** @var list<class-string<AssetBundle>> */
    public array $depends = [
        MainAsset::class,
    ];
}
