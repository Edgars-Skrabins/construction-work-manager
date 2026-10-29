<?php

declare(strict_types=1);

namespace App\Web\SetPassword;

use Yiisoft\Validator\Label;
use Yiisoft\Validator\Result;
use Yiisoft\Validator\Rule\Callback;
use Yiisoft\Validator\Rule\CompareType;
use Yiisoft\Validator\Rule\Equal;
use Yiisoft\Validator\Rule\Length;
use Yiisoft\Validator\Rule\Required;

final class SetPasswordForm
{
    #[Required]
    public string $email = '';

    #[Label('Setup code')]
    #[Required]
    public string $setupCode = '';

    #[Label('New password')]
    #[Required]
    #[Length(min: 8, lessThanMinMessage: 'Use at least 8 characters.')]
    #[Callback(method: 'checkPasswordBytes')]
    public string $password = '';

    #[Label('Repeat password')]
    #[Equal(targetProperty: 'password', message: "The passwords don't match.", type: CompareType::STRING)]
    public string $passwordRepeat = '';

    public static function fromBody(mixed $body): self
    {
        $form = new self();
        $form->email = self::field($body, 'email', true);
        $form->setupCode = self::field($body, 'setupCode', true);
        $form->password = self::field($body, 'password', false);
        $form->passwordRepeat = self::field($body, 'passwordRepeat', false);

        return $form;
    }

    public function checkPasswordBytes(mixed $value): Result
    {
        $result = new Result();
        if (is_string($value) && strlen($value) > 72) {
            $result->addError('Use a shorter password (at most 72 bytes).');
        }

        return $result;
    }

    private static function field(mixed $body, string $name, bool $trim): string
    {
        $value = is_array($body) && is_string($body[$name] ?? null) ? $body[$name] : '';

        return $trim ? trim($value) : $value;
    }
}
