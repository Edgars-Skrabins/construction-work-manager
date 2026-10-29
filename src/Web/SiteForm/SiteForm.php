<?php

declare(strict_types=1);

namespace App\Web\SiteForm;

use App\Entity\ConstructionSite;
use App\Repository\ConstructionSiteData;
use App\Web\Shared\Form\FormBody;
use Yiisoft\Validator\Label;
use Yiisoft\Validator\Rule\In;
use Yiisoft\Validator\Rule\Length;
use Yiisoft\Validator\Rule\Regex;
use Yiisoft\Validator\Rule\Required;

final class SiteForm
{
    #[Label('Name')]
    #[Required]
    #[Length(max: 150)]
    public string $name = '';

    #[Label('Street')]
    #[Required]
    #[Length(max: 200)]
    public string $street = '';

    #[Label('City')]
    #[Required]
    #[Length(max: 100)]
    public string $city = '';

    #[Label('Country')]
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
        $form->name = FormBody::string($body, 'name');
        $form->street = FormBody::string($body, 'street');
        $form->city = FormBody::string($body, 'city');
        $form->country = FormBody::string($body, 'country');
        $form->area = FormBody::string($body, 'area');
        $form->requiredAccessLevelId = FormBody::string($body, 'requiredAccessLevelId');

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
}
