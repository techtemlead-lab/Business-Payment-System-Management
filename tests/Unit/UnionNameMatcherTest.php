<?php

namespace Tests\Unit;

use App\Domain\Geography\UnionNameMatcher;
use PHPUnit\Framework\TestCase;

class UnionNameMatcherTest extends TestCase
{
    private const Names = ['Hatibanda', 'Kaharol', 'Tentulia', 'Mithapukur', 'Gangachora', 'Shibpur', 'Sibpur Char'];

    public function test_each_tier_finds_its_spelling_variants(): void
    {
        $matcher = new UnionNameMatcher;

        $this->assertSame(['match' => 'Kaharol', 'tier' => 'exact', 'candidates' => ['Kaharol']], $matcher->find('Kaharol Union', self::Names));
        $this->assertSame('folded', $matcher->find('Hatibandha', self::Names)['tier']);
        $this->assertSame('Hatibanda', $matcher->find('Hatibandha', self::Names)['match']);
        $this->assertSame(['Kaharol', 'skeleton'], [$matcher->find('Kaharole', self::Names)['match'], $matcher->find('Kaharole', self::Names)['tier']]);
        $this->assertSame(['Gangachora', 'skeleton'], [$matcher->find('Gangachara', self::Names)['match'], $matcher->find('Gangachara', self::Names)['tier']]);
        $this->assertSame(['Tentulia', 'close'], [$matcher->find('Tetulia', self::Names)['match'], $matcher->find('Tetulia', self::Names)['tier']]);
        $this->assertSame('Mithapukur', $matcher->find('Mithapukur Sadar', self::Names)['match']);
    }

    public function test_an_ambiguous_or_unknown_name_gives_no_match(): void
    {
        $matcher = new UnionNameMatcher;

        // Two names that differ only in vowels: the skeleton tier has two hits, so nothing is chosen.
        $ambiguous = $matcher->find('Shibpar', ['Shibpur', 'Shibpor']);
        $this->assertNull($ambiguous['match']);
        $this->assertSame(['Shibpur', 'Shibpor'], $ambiguous['candidates']);
        $this->assertNull($matcher->find('Totally Different', self::Names)['match']);
        $this->assertNull($matcher->find('', self::Names)['match']);
        // Short names never match by consonants alone.
        $this->assertNull($matcher->find('Ata', ['Ita', 'Oto'])['match']);
    }
}
