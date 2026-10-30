<?php

declare(strict_types=1);

namespace App\Web\Shared\Layout\Main;

use Yiisoft\Assets\AssetBundle;

final class AssignmentAsset extends AssetBundle
{
    public ?string $basePath = '@assets/assignment';
    public ?string $baseUrl = '@assetsUrl/assignment';
    public ?string $sourcePath = '@assetsSource/assignment';

    /** @var list<string> */
    public array $js = [
        'assignment.js',
    ];

    /** @var list<class-string<AssetBundle>> */
    public array $depends = [
        MainAsset::class,
        DateInputAsset::class,
    ];
}
