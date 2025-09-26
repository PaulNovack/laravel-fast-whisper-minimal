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

    /** Empties the list (session-backed). Returns the new empty list. */
    public function clear(): array
    {
        $this->session->put($this->key, []);
        return [];
    }

    public function add(string $item): array
    {
        $normalized = $this->normalizeItem($item);
        if ($normalized === '') {
            return $this->all();
        }

        $items = $this->all();

        if ($this->dedupeCaseInsensitive) {
            $lower = array_map(fn($i) => mb_strtolower($i), $items);
            if (in_array(mb_strtolower($normalized), $lower, true)) {
                return $items;
            }
        } else {
            if (in_array($normalized, $items, true)) {
                return $items;
            }
        }

        $items[] = $normalized;
        $this->session->put($this->key, $items);
        return $items;
    }

    public function remove(string $item): array
    {
        $needle = $this->normalizeItem($item);
        if ($needle === '') {
            return $this->all();
        }

        $filtered = array_values(array_filter(
            $this->all(),
            fn ($i) => mb_strtolower($i) !== mb_strtolower($needle)
        ));

        $this->session->put($this->key, $filtered);
        return $filtered;
    }

    /**
     * Supports:
     *  - "add 2 tomatoe sauce", "add apples, bananas and pears"
     *  - "remove tomatoe sauce"
     *  - "clear list" / "delete list" / "new list"  -> empties list
     */
    public function processCommand(string $text): array
    {
        $raw = trim($text ?? '');

        // CLEAR commands (no items expected)
        if ($this->isClearCommand($raw)) {
            return ['action' => 'clear', 'items' => $this->clear()];
        }

        // ADD
        if (preg_match('/^\s*(add|plus|include)\s+(.+)$/iu', $raw, $m)) {
            foreach ($this->splitItems($m[2]) as $p) {
                $this->add($p);
            }
            return ['action' => 'add', 'items' => $this->all()];
        }

        // REMOVE
        if (preg_match('/^\s*(remove|delete|minus|drop)\s+(.+)$/iu', $raw, $m)) {
            foreach ($this->splitItems($m[2]) as $p) {
                $this->remove($p);
            }
            return ['action' => 'remove', 'items' => $this->all()];
        }

        // No-op → just return current list
        return ['action' => 'noop', 'items' => $this->all()];
    }

    private function isClearCommand(string $raw): bool
    {
        $lc = mb_strtolower(trim($raw));
        // Accept variations with or without the trailing word “list”
        // e.g., "clear", "clear list", "delete list", "new list", "start new list"
        return
            preg_match('/^\s*(clear|reset)\s*(list)?\s*$/u', $lc) ||
            preg_match('/^\s*(delete|wipe|erase)\s+list\s*$/u', $lc) ||
            preg_match('/^\s*(new|create new|start new)\s+list\s*$/u', $lc);
    }

    // --- (everything below unchanged: split/normalize helpers) ---

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
        $name = $this->collapseSpaces($this->stripPunctuation($name));
        return $name;
    }

    private function toTitle(string $s): string
    {
        return mb_convert_case($s, MB_CASE_TITLE, 'UTF-8');
    }
}
