<?php

declare(strict_types=1);

namespace App\Web\Shared\Layout\Main;

use Yiisoft\Assets\AssetBundle;

final class SelectAsset extends AssetBundle
{
    public ?string $basePath = '@assets/select';
    public ?string $baseUrl = '@assetsUrl/select';
    public ?string $sourcePath = '@assetsSource/select';

    /** @var list<string> */
    public array $css = [
        'select.css',
    ];

    /** @var list<string> */
    public array $js = [
        'select.js',
    ];

    /** @var list<class-string<AssetBundle>> */
    public array $depends = [
        BootstrapAsset::class,
    ];
}
