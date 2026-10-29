<?php

declare(strict_types=1);

namespace App\Web\Shared\Layout\Main;

use Yiisoft\Assets\AssetBundle;

final class EmployeeAsset extends AssetBundle
{
    public ?string $basePath = '@assets/employee';
    public ?string $baseUrl = '@assetsUrl/employee';
    public ?string $sourcePath = '@assetsSource/employee';

    /** @var list<string> */
    public array $js = [
        'employee.js',
    ];

    /** @var list<class-string<AssetBundle>> */
    public array $depends = [
        MainAsset::class,
    ];
}
