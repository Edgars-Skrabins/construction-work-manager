<?php

declare(strict_types=1);

namespace App\Web\SetPassword;

use App\Web\Shared\Form\FormBody;
use Yiisoft\Validator\Label;
use Yiisoft\Validator\Result;
use Yiisoft\Validator\Rule\Callback;
use Yiisoft\Validator\Rule\CompareType;
use Yiisoft\Validator\Rule\Equal;
use Yiisoft\Validator\Rule\Length;
use Yiisoft\Validator\Rule\Required;

final class SetPasswordForm
{
    #[Label('Email')]
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
        $form->email = FormBody::string($body, 'email');
        $form->setupCode = FormBody::string($body, 'setupCode');
        $form->password = FormBody::string($body, 'password', trim: false);
        $form->passwordRepeat = FormBody::string($body, 'passwordRepeat', trim: false);

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
}
