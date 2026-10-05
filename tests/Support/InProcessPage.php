<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Support;

use CattoLearning\Auth\AuthService;
use CattoLearning\Auth\CurrentUser;
use CattoLearning\Http\HttpRedirect;
use ReflectionClass;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Runs one real controller action inside the test process, on the integration container and so on
 * the test's own database connection and transaction. A kernel request opens its own connection
 * and cannot see a rolled-back fixture; this can, which lets tests of commerce pages leave no
 * immutable order behind.
 *
 * The identity is set on the container's own AuthService for the duration of the call, so every
 * service the controller uses sees the same person.
 */
final class InProcessPage
{
    /**
     * @param class-string $controller
     * @param array<string,mixed> $attributes route attributes such as an id
     * @param array<string,mixed>|null $post a POST body, or null for GET
     * @param array<string,mixed> $query
     * @return array{status:int,body:string,location:?string}
     */
    public static function run(?CurrentUser $user, string $controller, string $method, array $attributes = [], ?array $post = null, array $query = []): array
    {
        $container = IntegrationContainer::get();
        $auth = $container->get(AuthService::class);
        $identity = new ReflectionProperty(AuthService::class, 'currentUser');
        $previous = $identity->getValue($auth);
        $identity->setValue($auth, $user);
        $request = $post === null ? Request::create('/', 'GET', $query) : Request::create('/', 'POST', $post);
        foreach ($attributes as $key => $value) $request->attributes->set($key, $value);
        $stack = new RequestStack();
        $stack->push($request);
        [$savedPost, $savedGet] = [$_POST, $_GET];
        $_POST = $post ?? [];
        $_GET = $query;
        try {
            $class = new ReflectionClass($controller);
            $arguments = [];
            foreach ($class->getConstructor()?->getParameters() ?? [] as $parameter) {
                $type = (string) $parameter->getType();
                $arguments[] = $type === RequestStack::class ? $stack : $container->get($type);
            }
            $response = $class->newInstanceArgs($arguments)->{$method}();
            return ['status' => $response->getStatusCode(), 'body' => (string) $response->getContent(), 'location' => $response->headers->get('Location')];
        } catch (HttpRedirect $redirect) {
            return ['status' => $redirect->status, 'body' => '', 'location' => $redirect->path];
        } finally {
            [$_POST, $_GET] = [$savedPost, $savedGet];
            $identity->setValue($auth, $previous);
        }
    }
}
