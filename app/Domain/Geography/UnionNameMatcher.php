<?php

namespace App\Domain\Geography;

/**
 * Finds a union name written one way in a source among union names written another way in our
 * list (Bengali names are transliterated differently everywhere: Hatibandha / Hatibanda,
 * Kaharole / Kaharol, Dhulauri / Dhulaury).
 *
 * Four tiers, strictest first. The first tier that has any hit decides, and it answers only when
 * that tier has exactly one hit; two hits are "ambiguous" and nothing is chosen:
 *   1. exact: same letters once case, punctuation and the words Union / Sadar are dropped
 *   2. folded: after merging common spelling variants (oo/u, ee/i, ph/f, w/v/b, sh/s, z/j, double letters…)
 *   3. skeleton: the same consonants in the same order, vowels ignored (only for names of 4+ letters)
 *   4. close: folded names one edit apart (only for names of 6+ letters)
 */
final class UnionNameMatcher
{
    /**
     * @param  list<string>  $names
     * @return array{match: ?string, tier: ?string, candidates: list<string>}
     */
    public function find(string $wanted, array $names): array
    {
        $wantedExact = $this->exact($wanted);
        if ($wantedExact === '') {
            return ['match' => null, 'tier' => null, 'candidates' => []];
        }
        $tiers = [
            'exact' => [$wantedExact, fn (string $n): string => $this->exact($n)],
            'folded' => [$this->fold($wanted), fn (string $n): string => $this->fold($n)],
            'skeleton' => [$this->skeleton($wanted), fn (string $n): string => $this->skeleton($n)],
        ];
        foreach ($tiers as $tier => [$key, $of]) {
            if ($tier === 'skeleton' && mb_strlen($key) < 4) {
                continue;
            }
            $hits = array_values(array_filter($names, fn (string $n): bool => $of($n) === $key));
            if ($hits !== []) {
                return count($hits) === 1
                    ? ['match' => $hits[0], 'tier' => $tier, 'candidates' => $hits]
                    : ['match' => null, 'tier' => null, 'candidates' => $hits];
            }
        }

        $folded = $this->fold($wanted);
        if (mb_strlen($folded) >= 6) {
            $hits = array_values(array_filter($names, fn (string $n): bool => mb_strlen($this->fold($n)) >= 6 && levenshtein($this->fold($n), $folded) === 1));
            if ($hits !== []) {
                return count($hits) === 1
                    ? ['match' => $hits[0], 'tier' => 'close', 'candidates' => $hits]
                    : ['match' => null, 'tier' => null, 'candidates' => $hits];
            }
        }

        return ['match' => null, 'tier' => null, 'candidates' => []];
    }

    private function exact(string $name): string
    {
        $key = (string) preg_replace('/[^a-z0-9]+/', '', mb_strtolower($name));

        return (string) preg_replace('/(union|sadar)$/', '', $key);
    }

    private function fold(string $name): string
    {
        $key = $this->exact($name);
        $key = strtr($key, ['oo' => 'u', 'ee' => 'i', 'ou' => 'u', 'aa' => 'a', 'ph' => 'f', 'sh' => 's', 'ch' => 'c', 'kh' => 'k', 'gh' => 'g', 'dh' => 'd', 'th' => 't', 'bh' => 'b', 'jh' => 'j', 'w' => 'v', 'z' => 'j', 'y' => 'i', 'q' => 'k']);
        $key = str_replace('v', 'b', $key);

        return (string) preg_replace('/(.)\1+/', '$1', $key);
    }

    private function skeleton(string $name): string
    {
        return (string) preg_replace('/[aeiou]/', '', $this->fold($name));
    }
}
