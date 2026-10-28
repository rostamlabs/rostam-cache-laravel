<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Rostam\Cache\Tests\Unit;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\TagSet;
use PHPUnit\Framework\TestCase;
use Rostam\Cache\RostamStore;
use Rostam\Cache\Tags\RefreshingTagSet;
use Rostam\Testing\ArrayKvClient;

/**
 * A tag id is a small key written once and only ever read afterwards, which on
 * a store that evicts by WRITE ORDER is the first thing to be overwritten -
 * and losing it silently takes every entry under that tag out of reach. These
 * tests pin the two things that stop it: the id is rewritten once it has aged,
 * and reading a whole set costs one round trip rather than one per tag.
 */
class RefreshingTagSetTest extends TestCase
{
    private ArrayKvClient $client;

    private RostamStore $store;

    /** Stands in for the clock so ageing is decided, not waited for. */
    private int $now = 1_000_000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new ArrayKvClient;
        $this->store = RostamStore::make($this->client, 'app:', ['epoch_refresh' => -1]);
    }

    private function tagSet(array $names, int $refresh = 300): RefreshingTagSet
    {
        return new RefreshingTagSet($this->store, $names, $refresh, fn () => $this->now);
    }

    public function test_it_mints_an_id_and_keeps_answering_with_it(): void
    {
        $set = $this->tagSet(['people']);

        $first = $set->getNamespace();

        $this->assertNotSame('', $first);
        $this->assertSame($first, $this->tagSet(['people'])->getNamespace());
    }

    public function test_a_fresh_id_is_not_rewritten(): void
    {
        $this->tagSet(['people'])->getNamespace();

        $this->client->ops = [];
        $this->now += 60;                       // well inside the 300s window

        $this->tagSet(['people'])->getNamespace();

        $this->assertNotContains('put', $this->client->ops, 'a young tag id must not be rewritten');
    }

    public function test_an_aged_id_is_rewritten_but_keeps_its_value(): void
    {
        $set = $this->tagSet(['people']);
        $id = $set->getNamespace();

        $this->now += 301;

        $this->client->ops = [];
        $again = $this->tagSet(['people'])->getNamespace();

        $this->assertSame($id, $again, 'refreshing must not change the id - that would flush the tag');
        $this->assertContains('put', $this->client->ops, 'an aged tag id must be written back');
    }

    public function test_refreshing_can_be_switched_off(): void
    {
        $this->tagSet(['people'], refresh: 0)->getNamespace();

        $this->now += 10_000;
        $this->client->ops = [];

        $this->tagSet(['people'], refresh: 0)->getNamespace();

        $this->assertNotContains('put', $this->client->ops);
    }

    public function test_a_whole_set_costs_one_round_trip(): void
    {
        $this->tagSet(['a', 'b', 'c'])->getNamespace();     // mint them

        $this->client->ops = [];
        $this->tagSet(['a', 'b', 'c'])->getNamespace();

        $this->assertSame(['getMany'], $this->client->ops, 'three tags must not cost three exchanges');
    }

    public function test_laravels_own_tag_set_costs_one_round_trip_per_tag(): void
    {
        // The behaviour being improved on, asserted so the comparison is not a
        // claim in a comment.
        (new TagSet($this->store, ['a', 'b', 'c']))->getNamespace();

        $this->client->ops = [];
        (new TagSet($this->store, ['a', 'b', 'c']))->getNamespace();

        $this->assertSame(['get', 'get', 'get'], $this->client->ops);
    }

    public function test_an_evicted_id_mints_a_new_one_rather_than_failing(): void
    {
        $set = $this->tagSet(['people']);
        $id = $set->getNamespace();

        $this->client->del('app:0:tag:people:key');        // the eviction

        $this->assertNotSame($id, $this->tagSet(['people'])->getNamespace());
    }

    /**
     * A cache shared with an older release - or with plain Laravel - holds tag
     * ids as bare strings. Treating one as unreadable would flush the tag on
     * upgrade, so it is adopted and stamped instead.
     */
    public function test_an_unstamped_legacy_id_is_adopted_not_discarded(): void
    {
        $this->store->forever('tag:people:key', 'legacy-id-from-before');

        $set = $this->tagSet(['people']);

        $this->assertSame('legacy-id-from-before', $set->getNamespace());

        // ...and it is now stamped, so it will be kept young from here on.
        $this->now += 301;
        $this->client->ops = [];
        $this->assertSame('legacy-id-from-before', $this->tagSet(['people'])->getNamespace());
        $this->assertContains('put', $this->client->ops);
    }

    public function test_a_corrupt_stamp_is_treated_as_missing(): void
    {
        $this->store->forever('tag:people:key', ['stamped', 'id-without-a-time']);

        $this->assertNotSame('', $this->tagSet(['people'])->getNamespace());
    }

    public function test_flushing_a_tag_still_orphans_its_entries(): void
    {
        $set = $this->tagSet(['people']);
        $before = $set->getNamespace();

        $set->reset();

        $this->assertNotSame($before, $this->tagSet(['people'])->getNamespace());
    }

    public function test_minting_a_whole_set_writes_it_in_one_round_trip(): void
    {
        $this->store->get('warm-up');                  // the flush generation is read once, first
        $this->client->ops = [];

        $this->tagSet(['a', 'b', 'c'])->getNamespace();

        $this->assertSame(['getMany', 'putMany'], $this->client->ops, 'three new tags must not cost three writes');
    }

    public function test_a_whole_aged_set_is_rewritten_in_one_round_trip(): void
    {
        $ids = $this->tagSet(['a', 'b', 'c'])->getNamespace();

        $this->now += 301;
        $this->client->ops = [];

        $this->assertSame($ids, $this->tagSet(['a', 'b', 'c'])->getNamespace(), 'refreshing must keep every id');
        $this->assertSame(['getMany', 'putMany'], $this->client->ops);
    }

    public function test_only_the_aged_tags_of_a_set_are_rewritten(): void
    {
        $this->tagSet(['a', 'b'])->getNamespace();
        $this->now += 200;
        $this->tagSet(['c'])->getNamespace();         // younger than a and b

        $this->now += 150;                            // a and b are 350s old, c is 150s
        $cWrittenAt = $this->store->get('tag:c:key')[2];
        $this->client->ops = [];

        $this->tagSet(['a', 'b', 'c'])->getNamespace();

        $this->assertSame(['getMany', 'putMany'], $this->client->ops);
        $this->assertSame($this->now, $this->store->get('tag:a:key')[2]);
        $this->assertSame($this->now, $this->store->get('tag:b:key')[2]);
        $this->assertSame($cWrittenAt, $this->store->get('tag:c:key')[2], 'a young tag must not be rewritten');
    }

    public function test_resetting_a_set_writes_every_new_id_in_one_round_trip(): void
    {
        $set = $this->tagSet(['a', 'b', 'c']);
        $before = $set->getNamespace();

        $this->client->ops = [];
        $set->reset();

        $this->assertSame(['putMany'], $this->client->ops, 'a flush of three tags must not cost three writes');
        $this->assertNotSame($before, $this->tagSet(['a', 'b', 'c'])->getNamespace());
    }

    public function test_every_reset_tag_gets_its_own_new_id(): void
    {
        $set = $this->tagSet(['a', 'b']);
        $set->getNamespace();
        $set->reset();

        $ids = array_map(fn (string $name) => $this->store->get('tag:'.$name.':key')[1], ['a', 'b']);

        $this->assertNotSame($ids[0], $ids[1]);
        $this->assertSame(implode('|', $ids), $this->tagSet(['a', 'b'])->getNamespace());
    }

    public function test_a_store_that_cannot_batch_is_written_one_tag_at_a_time(): void
    {
        $store = new ArrayStore;
        $set = new RefreshingTagSet($store, ['a', 'b'], 300, fn () => $this->now);

        $first = $set->getNamespace();
        $set->reset();

        $this->assertNotSame($first, $set->getNamespace());
        $this->assertSame('stamped', $store->get('tag:a:key')[0]);
        $this->assertSame('stamped', $store->get('tag:b:key')[0]);
    }

    public function test_the_store_writes_many_values_without_expiry(): void
    {
        $this->store->foreverMany(['one' => 1, 'two' => 'second']);

        $this->assertSame(1, $this->store->get('one'));
        $this->assertSame('second', $this->store->get('two'));
        $this->assertSame(-1, $this->client->ttl('app:0:one'));
        $this->assertSame(-1, $this->client->ttl('app:0:two'));
        $this->assertTrue($this->store->foreverMany([]));
    }

    public function test_the_store_uses_it_through_the_repository(): void
    {
        $this->store->tags(['people'])->put('anne', 'Anne', 60);

        $this->assertSame('Anne', $this->store->tags(['people'])->get('anne'));
        $this->assertNull($this->store->tags(['other'])->get('anne'));
    }
}
