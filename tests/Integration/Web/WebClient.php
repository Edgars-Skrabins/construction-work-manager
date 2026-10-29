<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Yiisoft\Yii\Http\Application;

trait WebClient
{
    /** @var array<string, string> */
    private array $cookies = [];

    private function logIn(string $email, string $password): ResponseInterface
    {
        $token = $this->csrfToken((string) $this->request('GET', '/login')->getBody());

        return $this->request('POST', '/login', ['_csrf' => $token, 'email' => $email, 'password' => $password]);
    }

    private function csrfToken(string $html): string
    {
        preg_match('/name="_csrf" value="([^"]+)"/', $html, $matches);

        return $matches[1] ?? '';
    }

    /**
     * @param array<string, string> $body
     */
    private function request(string $method, string $path, array $body = []): ResponseInterface
    {
        $this->resetServices();

        $request = $this->service(ServerRequestFactoryInterface::class)
            ->createServerRequest($method, 'http://localhost' . $path)
            ->withCookieParams($this->cookies);
        if ($method === 'POST') {
            $request = $request->withParsedBody($body);
        }
        $query = parse_url($path, PHP_URL_QUERY);
        if (is_string($query)) {
            parse_str($query, $params);
            $request = $request->withQueryParams($params);
        }

        $response = $this->service(Application::class)->handle($request);

        foreach ($response->getHeader('Set-Cookie') as $cookie) {
            [$pair] = explode(';', $cookie, 2);
            [$name, $value] = explode('=', $pair, 2);
            $this->cookies[$name] = urldecode($value);
        }

        return $response;
    }

    /**
     * @param array<string, string> $fields
     */
    private function submit(string $path, array $fields): ResponseInterface
    {
        $token = $this->csrfToken((string) $this->request('GET', $path)->getBody());

        return $this->request('POST', $path, ['_csrf' => $token, ...$fields]);
    }
}
