<?php

declare(strict_types=1);

namespace App\Web\Shared\Layout\Main;

use Yiisoft\Assets\AssetBundle;

final class ModalAsset extends AssetBundle
{
    public ?string $basePath = '@assets/modal';
    public ?string $baseUrl = '@assetsUrl/modal';
    public ?string $sourcePath = '@assetsSource/modal';

    /** @var list<string> */
    public array $js = [
        'modal.js',
    ];

    /** @var list<class-string<AssetBundle>> */
    public array $depends = [
        MainAsset::class,
    ];
}
