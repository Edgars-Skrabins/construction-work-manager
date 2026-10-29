<?php

declare(strict_types=1);

namespace App\Web\Language;

use App\Web\Shared\Form\FormBody;
use App\Web\Shared\Language\Language;
use App\Web\Shared\Redirect\Redirector;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class Action
{
    public function __construct(
        private Redirector $redirector,
        private LanguageCookie $cookie,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $returnUrl = FormBody::string($body, 'returnUrl', trim: false);
        $response = LocalPath::isLocal($returnUrl)
            ? $this->redirector->to($returnUrl)
            : $this->redirector->toRoute('home');

        $language = Language::tryFrom(FormBody::string($body, 'locale'));

        return $language === null ? $response : $this->cookie->withCookie($response, $language);
    }
}
