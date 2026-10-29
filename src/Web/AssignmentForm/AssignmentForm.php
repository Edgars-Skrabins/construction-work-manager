<?php

declare(strict_types=1);

namespace App\Web\AssignmentForm;

use App\Entity\Assignment;
use App\Web\Shared\Form\FormBody;
use Yiisoft\Validator\Label;
use Yiisoft\Validator\Rule\Required;
use Yiisoft\Validator\RulesProviderInterface;

final class AssignmentForm implements RulesProviderInterface
{
    #[Label('Employee')]
    public string $employee = '';

    #[Label('Work item')]
    public string $workItem = '';

    #[Label('Construction site')]
    public string $constructionSite = '';

    #[Label('Start date')]
    public string $startDate = '';

    #[Label('End date')]
    public string $endDate = '';

    #[Label('Status')]
    public string $status = '';

    public function __construct(
        private readonly bool $editing = false,
    ) {}

    public static function fromBody(mixed $body, bool $editing): self
    {
        $form = new self($editing);
        foreach (['employee', 'workItem', 'constructionSite', 'startDate', 'endDate', 'status'] as $name) {
            $form->$name = FormBody::string($body, $name);
        }

        return $form;
    }

    public static function fromAssignment(Assignment $assignment): self
    {
        $form = new self(true);
        $form->startDate = $assignment->startDate->format('Y-m-d');
        $form->endDate = $assignment->endDate->format('Y-m-d');
        $form->status = $assignment->status->value;

        return $form;
    }

    public function getRules(): iterable
    {
        $rules = [
            'startDate' => [new Required(message: 'Enter a start date.')],
            'endDate' => [new Required(message: 'Enter an end date.')],
        ];

        return $this->editing
            ? [...$rules, 'status' => [new Required(message: 'Choose a status.')]]
            : [
                'employee' => [new Required(message: 'Choose an employee.')],
                'workItem' => [new Required(message: 'Choose a work item.')],
                'constructionSite' => [new Required(message: 'Choose a construction site.')],
                ...$rules,
            ];
    }
}
