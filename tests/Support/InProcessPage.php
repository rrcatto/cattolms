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
     * @return array{status:int,body:string,location:?string,headers:array<string,string>}
     */
    public static function run(?CurrentUser $user, string $controller, string $method, array $attributes = [], ?array $post = null, array $query = []): array
    {
        $container = IntegrationContainer::get();
        $auth = $container->get(AuthService::class);
        $identity = new ReflectionProperty(AuthService::class, 'currentUser');
        $previous = $identity->getValue($auth);
        $identity->setValue($auth, $user);
        // At the action's own address, its route's values filled in, as a browser asks for it: a refused form keeps what
        // was typed under the address it was sent to (Http\TypedForm).
        $path = self::path($controller, $method, $attributes);
        $request = $post === null ? Request::create($path, 'GET', $query) : Request::create($path, 'POST', $post);
        foreach ($attributes as $key => $value) $request->attributes->set($key, $value);
        $stack = new RequestStack();
        $stack->push($request);
        // The current request for the whole application too, as a real one is: CattoPHP's template functions (a refused
        // form's typed_for) find it there.
        $shared = $container->get('request_stack');
        $shared->push($request);
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
            // An action that takes the request or a route value (the tag page's tag($request, $slug)) is given them.
            $values = [];
            foreach ($class->getMethod($method)->getParameters() as $parameter) {
                $values[] = (string) $parameter->getType() === Request::class ? $request : ($attributes[$parameter->getName()] ?? null);
            }
            $response = $class->newInstanceArgs($arguments)->{$method}(...$values);
            $headers = [];
            foreach ($response->headers->all() as $name => $values) $headers[(string) $name] = implode(', ', array_map('strval', $values));
            return ['status' => $response->getStatusCode(), 'body' => (string) $response->getContent(), 'location' => $response->headers->get('Location'), 'headers' => $headers];
        } catch (HttpRedirect $redirect) {
            return ['status' => $redirect->status, 'body' => '', 'location' => $redirect->path, 'headers' => []];
        } finally {
            $shared->pop();
            [$_POST, $_GET] = [$savedPost, $savedGet];
            $identity->setValue($auth, $previous);
        }
    }

    /**
     * The action's address from its route, each {value} taken from $attributes; / for an action without a route.
     *
     * @param class-string $controller
     * @param array<string,mixed> $attributes
     */
    private static function path(string $controller, string $method, array $attributes): string
    {
        foreach ((new \ReflectionMethod($controller, $method))->getAttributes(\Symfony\Component\Routing\Attribute\Route::class) as $route) {
            $path = (string) $route->newInstance()->path;
            return (string) preg_replace_callback('/\{([a-z_]+)\}/', static fn(array $name): string => rawurlencode((string) ($attributes[$name[1]] ?? $name[0])), $path);
        }
        return '/';
    }
}
