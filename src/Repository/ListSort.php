<?php

declare(strict_types=1);

namespace App\Repository;

use Yiisoft\Data\Reader\Sort;

final class ListSort
{
    /**
     * @param array<string, array{asc: array<string, int>, desc: array<string, int>}> $config
     */
    public static function create(array $config, string $order, string $default): Sort
    {
        $sort = Sort::only($config)->withoutDefaultSorting();
        $requested = $sort->withOrderString($order)->getOrder();
        $field = array_key_first($requested);

        if ($field === null || !$sort->hasFieldInConfig($field)) {
            return $sort->withOrderString($default);
        }

        return $sort->withOrder([$field => $requested[$field]]);
    }

    /**
     * @param list<string> $columns
     * @return array{asc: array<string, int>, desc: array<string, int>}
     */
    public static function columns(array $columns, string $idColumn): array
    {
        $columns[] = $idColumn;

        return [
            'asc' => array_fill_keys($columns, SORT_ASC),
            'desc' => array_fill_keys($columns, SORT_DESC),
        ];
    }
}
