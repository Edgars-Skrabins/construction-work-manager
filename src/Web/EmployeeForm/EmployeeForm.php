<?php

declare(strict_types=1);

namespace App\Web\EmployeeForm;

use App\Access\Role;
use App\Entity\Employee;
use App\Service\EmployeeInput;
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

    #[In([1, 2, 3, 4], message: 'Choose an access level.')]
    public string $accessLevelId = '';

    #[In(['admin', 'manager', 'employee'], message: 'Choose a role.')]
    public string $role = '';

    #[Regex('/^\d{0,10}$/', message: 'Choose a manager.')]
    public string $managerId = '';

    #[Email(skipOnEmpty: true)]
    #[Length(max: 254, skipOnEmpty: true)]
    public string $email = '';

    public bool $loginActive = true;

    public static function fromBody(mixed $body): self
    {
        $form = new self();
        $form->firstName = self::field($body, 'firstName');
        $form->lastName = self::field($body, 'lastName');
        $form->birthDate = self::field($body, 'birthDate');
        $form->accessLevelId = self::field($body, 'accessLevelId');
        $form->role = self::field($body, 'role');
        $form->managerId = self::field($body, 'managerId');
        $form->email = self::field($body, 'email');
        $form->loginActive = self::field($body, 'loginActive') === '1';

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

    public function checkBirthDate(mixed $value): Result
    {
        $result = new Result();
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

        if ($date === false || $date->format('Y-m-d') !== $value) {
            return $result->addError('Enter the birth date as YYYY-MM-DD.');
        }

        $today = new DateTimeImmutable('today');
        if ($date > $today->modify('-16 years')) {
            $result->addError('The employee must be at least 16 years old.');
        } elseif ($date <= $today->modify('-101 years')) {
            $result->addError('The employee must be at most 100 years old.');
        }

        return $result;
    }

    private static function field(mixed $body, string $name): string
    {
        return is_array($body) && is_string($body[$name] ?? null) ? trim($body[$name]) : '';
    }
}
