<?php

declare(strict_types=1);

namespace App\Web\SiteForm;

use App\Entity\ConstructionSite;
use App\Repository\ConstructionSiteData;
use Yiisoft\Validator\Label;
use Yiisoft\Validator\Rule\In;
use Yiisoft\Validator\Rule\Length;
use Yiisoft\Validator\Rule\Regex;
use Yiisoft\Validator\Rule\Required;

final class SiteForm
{
    #[Required]
    #[Length(max: 150)]
    public string $name = '';

    #[Required]
    #[Length(max: 200)]
    public string $street = '';

    #[Required]
    #[Length(max: 100)]
    public string $city = '';

    #[Required]
    #[Length(max: 100)]
    public string $country = '';

    #[Label('Area')]
    #[Required]
    #[Regex('/^(?!0+(\.0+)?$)\d{1,8}(\.\d{1,2})?$/', message: 'Enter an area greater than 0, at most 99999999.99, with at most two decimals.')]
    public string $area = '';

    #[Label('Required access level')]
    #[In([1, 2, 3, 4], message: 'Choose an access level.')]
    public string $requiredAccessLevelId = '';

    public static function fromBody(mixed $body): self
    {
        $form = new self();
        $form->name = self::field($body, 'name');
        $form->street = self::field($body, 'street');
        $form->city = self::field($body, 'city');
        $form->country = self::field($body, 'country');
        $form->area = self::field($body, 'area');
        $form->requiredAccessLevelId = self::field($body, 'requiredAccessLevelId');

        return $form;
    }

    public static function fromSite(ConstructionSite $site): self
    {
        $form = new self();
        $form->name = $site->name;
        $form->street = $site->street;
        $form->city = $site->city;
        $form->country = $site->country;
        $form->area = $site->areaM2;
        $form->requiredAccessLevelId = (string) $site->requiredAccessLevelId;

        return $form;
    }

    public function toData(): ConstructionSiteData
    {
        return new ConstructionSiteData(
            name: $this->name,
            street: $this->street,
            city: $this->city,
            country: $this->country,
            areaM2: $this->area,
            requiredAccessLevelId: (int) $this->requiredAccessLevelId,
        );
    }

    private static function field(mixed $body, string $name): string
    {
        return is_array($body) && is_string($body[$name] ?? null) ? trim($body[$name]) : '';
    }
}
