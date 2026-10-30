<?php

declare(strict_types=1);

namespace App\Web\AssignmentForm;

use App\Entity\Assignment;
use App\Entity\AssignmentDescription;
use App\Web\Shared\Form\FormBody;
use App\Web\Shared\Listing\ListParams;
use Yiisoft\Validator\Label;
use Yiisoft\Validator\Rule\Length;
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

    #[Label('Description')]
    #[Length(max: AssignmentDescription::MAX_LENGTH)]
    public string $description = '';

    public string $currentEmployee = '';

    public function __construct(
        private readonly bool $editing = false,
    ) {}

    public static function fromBody(mixed $body, bool $editing): self
    {
        $form = new self($editing);
        foreach (['employee', 'workItem', 'constructionSite', 'startDate', 'endDate', 'status', 'currentEmployee'] as $name) {
            $form->$name = FormBody::string($body, $name);
        }
        $form->description = AssignmentDescription::normalize(FormBody::string($body, 'description'));

        return $form;
    }

    public static function fromAssignment(Assignment $assignment): self
    {
        $form = new self(true);
        $form->employee = (string) $assignment->employeeId;
        $form->currentEmployee = (string) $assignment->employeeId;
        $form->startDate = $assignment->startDate->format('Y-m-d');
        $form->endDate = $assignment->endDate->format('Y-m-d');
        $form->status = $assignment->status->value;
        $form->description = $assignment->description ?? '';

        return $form;
    }

    public function expectedEmployeeId(): ?int
    {
        return preg_match(ListParams::POSITIVE_INT_PATTERN, $this->currentEmployee) === 1 ? (int) $this->currentEmployee : null;
    }

    public function getRules(): iterable
    {
        $employee = ['employee' => [new Required(message: 'Choose an employee.')]];
        $rules = [
            'startDate' => [new Required(message: 'Enter a start date.')],
            'endDate' => [new Required(message: 'Enter an end date.')],
        ];

        return $this->editing
            ? [...$employee, ...$rules, 'status' => [new Required(message: 'Choose a status.')]]
            : [
                ...$employee,
                'workItem' => [new Required(message: 'Choose a work item.')],
                'constructionSite' => [new Required(message: 'Choose a construction site.')],
                ...$rules,
            ];
    }
}
