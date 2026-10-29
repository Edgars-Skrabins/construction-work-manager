<?php

declare(strict_types=1);

use App\Access\ReadOnlyAssignmentsStorage;
use Yiisoft\Rbac\AssignmentsStorageInterface;
use Yiisoft\Rbac\Db\ItemsStorage;
use Yiisoft\Rbac\ItemsStorageInterface;

return [
    ItemsStorageInterface::class => [
        'class' => ItemsStorage::class,
        '__construct()' => [
            'tableName' => 'RBAC_ITEM',
            'childrenTableName' => 'RBAC_ITEM_CHILD',
        ],
    ],
    AssignmentsStorageInterface::class => ReadOnlyAssignmentsStorage::class,
];
