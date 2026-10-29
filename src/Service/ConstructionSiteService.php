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
use Yiisoft\Data\Paginator\OffsetPaginator;
use Yiisoft\Db\Exception\IntegrityException;

final readonly class ConstructionSiteService
{
    public function __construct(
        private ConstructionSiteRepositoryInterface $sites,
        private AccessLevelRepositoryInterface $accessLevels,
        private AccessChecker $accessChecker,
    ) {}

    /**
     * @return OffsetPaginator<int, ConstructionSite>
     */
    public function listPaginator(int $actorId, string $sort): OffsetPaginator
    {
        $this->accessChecker->ensure($actorId, Permission::SiteUpdate);

        return $this->sites->listPaginator($sort);
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

        return $this->sites->create($data);
    }

    public function update(int $actorId, int $id, ConstructionSiteData $data): void
    {
        $this->accessChecker->ensure($actorId, Permission::SiteUpdate);
        $this->validate($data, $id);

        $this->sites->update($id, $data);
    }

    public function delete(int $actorId, int $id): void
    {
        $this->accessChecker->ensure($actorId, Permission::SiteDelete);

        try {
            $this->sites->delete($id);
        } catch (IntegrityException $e) {
            if (!InUseException::isConstraintConflict($e)) {
                throw $e;
            }

            throw new InUseException("This construction site is still in use and can't be deleted.", 0, $e);
        }
    }

    private function validate(ConstructionSiteData $data, ?int $exceptId): void
    {
        $errors = [];
        if ($this->sites->nameExists($data->name, $exceptId)) {
            $errors['name'] = 'A construction site with this name already exists.';
        }
        if (!$this->accessLevels->exists($data->requiredAccessLevelId)) {
            $errors['requiredAccessLevelId'] = 'Choose an access level.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }
}
