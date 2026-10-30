<?php

declare(strict_types=1);

namespace App\Web\Shared\Layout\Main;

use Yiisoft\Assets\AssetBundle;

final class ListingAsset extends AssetBundle
{
    public ?string $basePath = '@assets/listing';
    public ?string $baseUrl = '@assetsUrl/listing';
    public ?string $sourcePath = '@assetsSource/listing';

    /** @var list<string> */
    public array $js = [
        'instant-search.js',
    ];

    /** @var list<class-string<AssetBundle>> */
    public array $depends = [
        MainAsset::class,
    ];
}
