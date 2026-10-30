<?php

declare(strict_types=1);

namespace App\Web\EmployeeForm;

use App\Access\Role;
use App\Entity\Employee;
use App\Entity\IsoDate;
use App\Service\EmployeeInput;
use App\Web\Shared\Form\FormBody;
use DateTimeImmutable;
use Yiisoft\Validator\Label;
use Yiisoft\Validator\Result;
use Yiisoft\Validator\Rule\Callback;
use Yiisoft\Validator\Rule\Email;
use Yiisoft\Validator\Rule\In;
use Yiisoft\Validator\Rule\Length;
use Yiisoft\Validator\Rule\Regex;
use Yiisoft\Validator\Rule\Required;

final class EmployeeForm
{
    #[Label('First name')]
    #[Required]
    #[Length(max: 100)]
    public string $firstName = '';

    #[Label('Last name')]
    #[Required]
    #[Length(max: 100)]
    public string $lastName = '';

    #[Label('Birth date')]
    #[Required]
    #[Callback(method: 'checkBirthDate')]
    public string $birthDate = '';

    #[Label('Access level')]
    #[In([1, 2, 3, 4], message: 'Choose an access level.')]
    public string $accessLevelId = '';

    #[Label('Role')]
    #[In(['admin', 'manager', 'employee'], message: 'Choose a role.')]
    public string $role = '';

    #[Label('Manager')]
    #[Regex('/^\d{0,10}$/', message: 'Choose a manager.')]
    public string $managerId = '';

    #[Label('Login email')]
    #[Email(skipOnEmpty: true)]
    #[Length(max: 254, skipOnEmpty: true)]
    public string $email = '';

    public bool $loginActive = true;

    public static function fromBody(mixed $body): self
    {
        $form = new self();
        $form->firstName = FormBody::string($body, 'firstName');
        $form->lastName = FormBody::string($body, 'lastName');
        $form->birthDate = FormBody::string($body, 'birthDate');
        $form->accessLevelId = FormBody::string($body, 'accessLevelId');
        $form->role = FormBody::string($body, 'role');
        $form->managerId = FormBody::string($body, 'managerId');
        $form->email = FormBody::string($body, 'email');
        $form->loginActive = FormBody::string($body, 'loginActive') === '1';

        return $form;
    }

    public static function fromEmployee(Employee $employee): self
    {
        $form = new self();
        $form->firstName = $employee->firstName;
        $form->lastName = $employee->lastName;
        $form->birthDate = $employee->birthDate->format('Y-m-d');
        $form->accessLevelId = (string) $employee->accessLevelId;
        $form->role = $employee->role->value;
        $form->managerId = $employee->managerId === null ? '' : (string) $employee->managerId;
        $form->email = $employee->account->email ?? '';
        $form->loginActive = $employee->account->isActive ?? true;

        return $form;
    }

    public function toInput(): EmployeeInput
    {
        return new EmployeeInput(
            firstName: $this->firstName,
            lastName: $this->lastName,
            birthDate: new DateTimeImmutable($this->birthDate),
            accessLevelId: (int) $this->accessLevelId,
            role: Role::from($this->role),
            managerId: $this->managerId === '' ? null : (int) $this->managerId,
            email: $this->email === '' ? null : $this->email,
            loginActive: $this->loginActive,
        );
    }

    public function managerLocked(): bool
    {
        return in_array(Role::tryFrom($this->role), [Role::Admin, Role::Manager], true);
    }

    public function checkBirthDate(mixed $value): Result
    {
        $result = new Result();
        if (!is_string($value) || IsoDate::parse($value) === null) {
            return $result->addError('Enter the birth date as YYYY-MM-DD.');
        }

        return $result;
    }
}
