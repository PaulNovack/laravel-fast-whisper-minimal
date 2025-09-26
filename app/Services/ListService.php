<?php

namespace App\Services;

use Illuminate\Contracts\Session\Session;

class ListService
{
    public function __construct(
        private readonly Session $session,
        private readonly string $key = 'user.list.items',
        private readonly bool $titleCaseItems = true,
        private readonly bool $dedupeCaseInsensitive = true
    ) {}

    public function all(): array
    {
        return $this->session->get($this->key, []);
    }

    public function clear(): array
    {
        $this->session->put($this->key, []);
        return [];
    }

    public function add(string $item): array
    {
        $normalized = $this->normalizeItem($item);
        if ($normalized === '') return $this->all();

        $items = $this->all();

        if ($this->dedupeCaseInsensitive) {
            $lower = array_map(fn($i) => mb_strtolower($i), $items);
            if (in_array(mb_strtolower($normalized), $lower, true)) return $items;
        } else {
            if (in_array($normalized, $items, true)) return $items;
        }

        $items[] = $normalized;
        $this->session->put($this->key, $items);
        return $items;
    }

    /**
     * Remove by fuzzy match (Levenshtein) when no exact case-insensitive match exists.
     * Leading quantity (e.g., "2 ") is ignored for matching.
     */
    public function remove(string $item): array
    {
        $items = $this->all();
        if (empty($items)) return $items;

        $needle = $this->normalizeItem($item);
        if ($needle === '') return $items;

        // exact (case-insensitive) first
        $needleKey = $this->normalizeForMatch($needle);
        foreach ($items as $it) {
            if ($this->normalizeForMatch($it) === $needleKey) {
                $filtered = array_values(array_filter(
                    $items,
                    fn ($i) => $this->normalizeForMatch($i) !== $needleKey
                ));
                $this->session->put($this->key, $filtered);
                return $filtered;
            }
        }

        // fuzzy fallback
        $bestIdx = $this->findClosestIndex($needleKey, $items);
        if ($bestIdx !== null) {
            unset($items[$bestIdx]);
            $items = array_values($items);
            $this->session->put($this->key, $items);
        }
        return $items;
    }

    /**
     * Commands:
     *  - "add <ANY TEXT…>" → treated as ONE item (no splitting)
     *  - "remove a, b and c" → may remove multiple (each fuzzy-matched)
     *  - "clear list" / "delete list" / "new list" → empties list
     *  - anything else → noop (returns current list)
     */
    public function processCommand(string $text): array
    {
        $raw = trim($text ?? '');

        // CLEAR
        if ($this->isClearCommand($raw)) {
            return ['action' => 'clear', 'items' => $this->clear()];
        }

        // ADD (single item only; do NOT split)
        if (preg_match('/^\s*(add|ed|yeah|and|plus|include)\s+(.+)$/iu', $raw, $m)) {
            $payload = $this->collapseSpaces($this->stripSurroundingQuotes($m[2]));
            if ($payload !== '') {
                $this->add($payload);   // treat whole payload as one item
            }
            return ['action' => 'add', 'items' => $this->all()];
        }

        // REMOVE (still supports multiple tokens)
        if (preg_match('/^\s*(remove|move to|move|removes|delete|minus|drop)\s+(.+)$/iu', $raw, $m)) {
            foreach ($this->splitItems($m[2]) as $p) {
                $this->remove($p);
            }
            return ['action' => 'remove', 'items' => $this->all()];
        }

        // No-op
        return ['action' => 'noop', 'items' => $this->all()];
    }

    private function isClearCommand(string $raw): bool
    {
        $lc = mb_strtolower(trim($raw));
        return
            preg_match('/^\s*(clear|reset)\s*(list)?\s*$/u', $lc) ||
            preg_match('/^\s*(delete|wipe|erase)\s+list\s*$/u', $lc) ||
            preg_match('/^\s*(new|create new|start new)\s+list\s*$/u', $lc);
    }

    // --- helpers ---

    /** For REMOVE only: split on commas/&/and into multiple tokens */
    private function splitItems(string $s): array
    {
        $normalized = preg_replace('/\s+(and|&)\s+/iu', ',', $s);
        $parts = preg_split('/\s*,\s*/u', (string) $normalized, -1, PREG_SPLIT_NO_EMPTY);
        $parts = array_map([$this, 'stripSurroundingQuotes'], $parts);
        $parts = array_map([$this, 'collapseSpaces'], $parts);
        return array_values(array_filter($parts, fn($p) => trim($p) !== ''));
    }

    private function stripSurroundingQuotes(string $s): string
    {
        $s = trim($s);
        if ((str_starts_with($s, '"') && str_ends_with($s, '"')) ||
            (str_starts_with($s, "'") && str_ends_with($s, "'"))) {
            $s = mb_substr($s, 1, mb_strlen($s) - 2);
        }
        return $s;
    }

    private function collapseSpaces(string $s): string
    {
        return preg_replace('/\s+/u', ' ', trim($s)) ?? '';
    }

    private function normalizeItem(string $s): string
    {
        $s = $this->collapseSpaces($this->stripPunctuation($s));
        if ($s === '') return '';

        if (preg_match('/^\s*(\d+)\s+(.*)$/u', $s, $m)) {
            $qty  = $m[1];
            $name = $this->cleanName($m[2]);
            $name = $this->titleCaseItems ? $this->toTitle($name) : $name;
            return $qty . ' ' . $name;
        }

        $name = $this->cleanName($s);
        $name = $this->titleCaseItems ? $this->toTitle($name) : $name;
        return $name;
    }

    private function stripPunctuation(string $s): string
    {
        return preg_replace('/^[\p{Z}\p{C}\p{P}]+|[\p{Z}\p{C}\p{P}]+$/u', '', $s) ?? '';
    }

    private function cleanName(string $name): string
    {
        return $this->collapseSpaces($this->stripPunctuation($name));
    }

    private function toTitle(string $s): string
    {
        return mb_convert_case($s, MB_CASE_TITLE, 'UTF-8');
    }

    /** normalize for matching (lowercase, drop leading qty, trim/clean) */
    private function normalizeForMatch(string $s): string
    {
        $s = mb_strtolower($this->cleanName($s));
        $s = preg_replace('/^\d+\s+/u', '', $s) ?? $s;
        return $s;
    }

    private function findClosestIndex(string $needleNormalized, array $haystack): ?int
    {
        if (empty($haystack)) return null;
        $bestIdx = null;
        $bestDist = PHP_INT_MAX;

        foreach ($haystack as $idx => $item) {
            $cand = $this->normalizeForMatch($item);
            $dist = levenshtein($needleNormalized, $cand);
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $bestIdx = $idx;
                if ($bestDist === 0) break;
            }
        }
        return $bestIdx;
    }
}
