<?php

declare(strict_types=1);

namespace App\Web\WorkItemForm;

use App\Entity\WorkItem;
use App\Repository\WorkItemData;
use App\Web\Shared\Form\FormBody;
use Yiisoft\Validator\Label;
use Yiisoft\Validator\Rule\Length;
use Yiisoft\Validator\Rule\Required;

final class WorkItemForm
{
    #[Label('Name')]
    #[Required]
    #[Length(max: 150)]
    public string $name = '';

    #[Label('Description')]
    #[Length(max: 1000)]
    public string $description = '';

    public static function fromBody(mixed $body): self
    {
        $form = new self();
        $form->name = FormBody::string($body, 'name');
        $form->description = FormBody::string($body, 'description');

        return $form;
    }

    public static function fromWorkItem(WorkItem $workItem): self
    {
        $form = new self();
        $form->name = $workItem->name;
        $form->description = $workItem->description ?? '';

        return $form;
    }

    public function toData(): WorkItemData
    {
        return new WorkItemData($this->name, $this->description === '' ? null : $this->description);
    }
}
