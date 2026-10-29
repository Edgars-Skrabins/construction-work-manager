<?php

declare(strict_types=1);

namespace App\Service;

use App\Access\AccessChecker;
use App\Access\Permission;
use App\Entity\AccessLevel;
use App\Entity\ConstructionSite;
use App\Repository\AccessLevelRepositoryInterface;
use App\Repository\ConstructionSiteData;
use App\Repository\ConstructionSiteRepositoryInterface;
use Closure;
use Yiisoft\Data\Paginator\OffsetPaginator;

final readonly class ConstructionSiteService
{
    private const string NAME_TAKEN = 'A construction site with this name already exists.';
    private const string CODE_TAKEN = 'A construction site with this code already exists.';

    public function __construct(
        private ConstructionSiteRepositoryInterface $sites,
        private AccessLevelRepositoryInterface $accessLevels,
        private AccessChecker $accessChecker,
    ) {}

    /**
     * @return OffsetPaginator<int, ConstructionSite>
     */
    public function listPaginator(int $actorId, string $sort, string $search): OffsetPaginator
    {
        $this->accessChecker->ensure($actorId, Permission::SiteUpdate);

        return $this->sites->listPaginator($sort, $search);
    }

    public function find(int $actorId, int $id): ?ConstructionSite
    {
        $this->accessChecker->ensure($actorId, Permission::SiteUpdate);

        return $this->sites->find($id);
    }

    /**
     * @return list<AccessLevel>
     */
    public function accessLevels(int $actorId): array
    {
        $this->accessChecker->ensure($actorId, Permission::SiteRead);

        return $this->accessLevels->all();
    }

    public function create(int $actorId, ConstructionSiteData $data): int
    {
        $this->accessChecker->ensure($actorId, Permission::SiteCreate);
        $this->validate($data, null);

        return $this->guardUnique(fn(): int => $this->sites->create($data));
    }

    public function update(int $actorId, int $id, ConstructionSiteData $data): void
    {
        $this->accessChecker->ensure($actorId, Permission::SiteUpdate);
        $this->validate($data, $id);

        $this->guardUnique(fn() => $this->sites->update($id, $data));
    }

    public function delete(int $actorId, int $id): void
    {
        $this->accessChecker->ensure($actorId, Permission::SiteDelete);

        InUseException::guard(fn() => $this->sites->delete($id), "This construction site is still in use and can't be deleted.");
    }

    private function validate(ConstructionSiteData $data, ?int $exceptId): void
    {
        $errors = [];
        if ($this->sites->codeExists($data->code, $exceptId)) {
            $errors['code'] = self::CODE_TAKEN;
        }
        if ($this->sites->nameExists($data->name, $exceptId)) {
            $errors['name'] = self::NAME_TAKEN;
        }
        if (!$this->accessLevels->exists($data->requiredAccessLevelId)) {
            $errors['requiredAccessLevelId'] = 'Choose an access level.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    /**
     * @template T
     * @param Closure(): T $write
     * @return T
     */
    private function guardUnique(Closure $write): mixed
    {
        return ValidationException::guardUnique(
            fn(): mixed => ValidationException::guardUnique($write, 'UQ_CONSTRUCTION_SITE_NAME', 'name', self::NAME_TAKEN),
            'UQ_CONSTRUCTION_SITE_CODE',
            'code',
            self::CODE_TAKEN,
        );
    }
}
