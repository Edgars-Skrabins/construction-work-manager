<?php

declare(strict_types=1);

namespace App\Repository;

use Yiisoft\Db\Expression\ExpressionInterface;
use Yiisoft\Db\Query\Query;

final class ListSearch
{
    private const array ESCAPED = ['%' => '[%]', '_' => '[_]', '[' => '[[]'];

    /**
     * @param list<string|ExpressionInterface> $columns
     */
    public static function apply(Query $query, string $search, array $columns): Query
    {
        if ($search === '') {
            return $query;
        }

        $value = strtr($search, self::ESCAPED);

        return $query->andWhere([
            'or',
            ...array_map(
                static fn(string|ExpressionInterface $column): array => ['like', $column, $value, 'escape' => false],
                $columns,
            ),
        ]);
    }
}
