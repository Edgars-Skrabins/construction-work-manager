<?php

declare(strict_types=1);

namespace App\Web\Shared\Layout\Main;

use Yiisoft\Assets\AssetBundle;

final class DateInputAsset extends AssetBundle
{
    public ?string $basePath = '@assets/date-input';
    public ?string $baseUrl = '@assetsUrl/date-input';
    public ?string $sourcePath = '@assetsSource/date-input';

    /** @var list<string> */
    public array $css = [
        'date-input.css',
    ];

    /** @var list<string> */
    public array $js = [
        'date-input.js',
    ];

    /** @var list<class-string<AssetBundle>> */
    public array $depends = [
        MainAsset::class,
    ];
}
