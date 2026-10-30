<?php

declare(strict_types=1);

namespace App\Web\Shared\Layout\Main;

use Yiisoft\Assets\AssetBundle;

final class MainAsset extends AssetBundle
{
    public ?string $basePath = '@assets/main';
    public ?string $baseUrl = '@assetsUrl/main';
    public ?string $sourcePath = '@assetsSource/main';

    /** @var list<string> */
    public array $css = [
        'site.css',
    ];

    /** @var list<class-string<AssetBundle>> */
    public array $depends = [
        BootstrapAsset::class,
        SelectAsset::class,
    ];
}
