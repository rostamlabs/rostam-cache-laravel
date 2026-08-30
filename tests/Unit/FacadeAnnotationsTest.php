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
}
