<?php

declare(strict_types=1);

namespace App\Web\Shared\Layout\Main;

use Yiisoft\Assets\AssetBundle;

final class BootstrapAsset extends AssetBundle
{
    public ?string $basePath = '@assets/bootstrap';
    public ?string $baseUrl = '@assetsUrl/bootstrap';
    public ?string $sourcePath = '@vendor/twbs/bootstrap/dist';

    /** @var list<string> */
    public array $css = [
        'css/bootstrap.min.css',
    ];

    /** @var list<string> */
    public array $js = [
        'js/bootstrap.bundle.min.js',
    ];
}
