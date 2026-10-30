<?php

declare(strict_types=1);

namespace App\Web\Shared\Layout\Main;

use Yiisoft\Assets\AssetBundle;
use Yiisoft\View\WebView;

final class ThemeAsset extends AssetBundle
{
    public ?string $basePath = '@assets/main';
    public ?string $baseUrl = '@assetsUrl/main';
    public ?string $sourcePath = '@assetsSource/main';

    /** @var list<string> */
    public array $js = [
        'theme.js',
    ];

    public ?int $jsPosition = WebView::POSITION_HEAD;
}
