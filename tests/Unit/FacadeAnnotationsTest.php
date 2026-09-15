<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Rostam\Cache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Rostam\Cache\Facades\Rostam;
use Rostam\Cache\RostamManager;
use Rostam\Kv\TcpClient;

/**
 * The facade's @method annotations are the only description of a surface that
 * is otherwise built at runtime by RostamManager::__call, so nothing else in
 * this suite touches them - and an annotation naming a method that does not
 * exist is not a stale comment, it is a call that fatals in production with no
 * earlier warning. That is exactly how `forget()` and `forgetMany()` survived
 * the rename to `del()`/`delMany()`, and how `incrementAndRead()` sat there
 * having never existed at all.
 *
 * So the annotations are checked against the real classes.
 */
class FacadeAnnotationsTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function annotatedMethods(): array
    {
        $doc = (new ReflectionClass(Rostam::class))->getDocComment();
        $this->assertIsString($doc, 'the facade has lost its annotation block');

        preg_match_all('/@method\s+static\s+.*?\s(\w+)\(/', $doc, $matches);

        return $matches[1];
    }

    /**
     * @return list<string>
     */
    private function callableMethods(): array
    {
        $names = [];

        // The manager answers for itself first, then forwards everything else
        // to the connection it hands out.
        foreach ([RostamManager::class, TcpClient::class] as $class) {
            foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if (! $method->isStatic() && ! str_starts_with($method->getName(), '__')) {
                    $names[] = $method->getName();
                }
            }
        }

        return array_values(array_unique($names));
    }

    public function test_every_annotated_method_actually_exists(): void
    {
        $missing = array_diff($this->annotatedMethods(), $this->callableMethods());

        $this->assertSame([], array_values($missing), 'the facade promises methods that would fatal when called: '
            .implode(', ', $missing));
    }

    public function test_every_client_method_is_annotated(): void
    {
        // Not every manager method belongs on the facade, but every method a
        // caller can reach THROUGH it should be discoverable from the docblock,
        // or the IDE quietly hides half the client.
        $client = array_map(
            static fn (ReflectionMethod $m) => $m->getName(),
            array_filter(
                (new ReflectionClass(TcpClient::class))->getMethods(ReflectionMethod::IS_PUBLIC),
                static fn (ReflectionMethod $m) => ! $m->isStatic() && ! str_starts_with($m->getName(), '__'),
            )
        );

        // These are plumbing rather than key-value surface: `call`/`pipeline`
        // take Command objects, `config` and `disconnect` are connection
        // lifecycle, and none of them reads as something to reach for through a
        // cache facade.
        $plumbing = ['call', 'pipeline', 'config', 'disconnect'];

        $undocumented = array_diff($client, $this->annotatedMethods(), $plumbing);

        $this->assertSame([], array_values($undocumented), 'client methods missing from the facade docblock: '
            .implode(', ', $undocumented));
    }

    /**
     * Names are not the whole promise. An annotation that still names a method
     * but no longer describes it - a parameter dropped in the client, a default
     * changed, a nullable return - is a wrong answer handed to every IDE and
     * every reader, and comparing names alone never sees it.
     */
    public function test_every_annotation_matches_the_method_it_names(): void
    {
        $doc = (string) (new ReflectionClass(Rostam::class))->getDocComment();

        preg_match_all('/@method\s+static\s+(\S+)\s+(\w+)\(([^)]*)\)/', $doc, $matches, PREG_SET_ORDER);

        $this->assertNotEmpty($matches, 'no annotations were parsed at all');

        $wrong = [];

        foreach ($matches as [, $returns, $name, $parameters]) {
            $method = $this->realMethod($name);

            if ($method === null) {
                continue;               // the other test reports a name that does not exist
            }

            $expected = self::signature($method);
            $actual = self::normalise($returns.' '.$name.'('.$parameters.')');

            if ($expected !== $actual) {
                $wrong[] = "{$name}: annotated as [{$actual}], is [{$expected}]";
            }
        }

        $this->assertSame([], $wrong, implode("\n", $wrong));
    }

    private function realMethod(string $name): ?ReflectionMethod
    {
        foreach ([RostamManager::class, TcpClient::class] as $class) {
            if ((new ReflectionClass($class))->hasMethod($name)) {
                $method = (new ReflectionClass($class))->getMethod($name);

                if ($method->isPublic() && ! $method->isStatic()) {
                    return $method;
                }
            }
        }

        return null;
    }

    /**
     * The method as an annotation would have to write it: return type, name,
     * and every parameter with its type and default.
     */
    private static function signature(ReflectionMethod $method): string
    {
        $parameters = [];

        foreach ($method->getParameters() as $parameter) {
            $part = ($parameter->hasType() ? (string) $parameter->getType().' ' : '').'$'.$parameter->getName();

            if ($parameter->isDefaultValueAvailable()) {
                $default = $parameter->isDefaultValueConstant()
                    ? (string) $parameter->getDefaultValueConstantName()
                    : var_export($parameter->getDefaultValue(), true);

                $part .= ' = '.$default;
            }

            $parameters[] = $part;
        }

        return self::normalise(
            (string) $method->getReturnType().' '.$method->getName().'('.implode(', ', $parameters).')'
        );
    }

    /**
     * Same signature written two ways - `Rostam\TimeUnit` against `TimeUnit`,
     * `array` against `list<string>`, one space against three - is the same
     * signature. What this must not forgive is a different one.
     */
    private static function normalise(string $signature): string
    {
        $signature = preg_replace('/\s+/', ' ', trim($signature)) ?? '';
        $signature = preg_replace('/[\w\\\\]+\\\\(\w+)/', '$1', $signature) ?? '';   // namespaces
        $signature = preg_replace('/\barray<[^>]*>|\blist<[^>]*>/', 'array', $signature) ?? '';

        return str_replace(['?', ' '], ['', ''], strtolower($signature));
    }
}
