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

    // ----------------------- ADD / REMOVE -----------------------

    public function add(string $item): array
    {
        // Parse qty (digits or words) + name
        [$incQty, $incName] = $this->parseQtyAndName($item);
        if ($incName === '') return $this->all();
        $incQty = max(1, $incQty);

        $items = $this->all();
        $incKey = $this->matchKey($incName); // lower, singularized last word

        // merge into existing
        foreach ($items as $idx => $existing) {
            [$curQty, $curName] = $this->splitQtyName($existing);
            $curKey = $this->matchKey($curName);
            if ($curKey === $incKey) {
                $newQty = max(1, $curQty ?: 1) + $incQty;
                $items[$idx] = $this->formatLabel($newQty, $curName); // pluralize display name by qty
                $this->session->put($this->key, array_values($items));
                return $items;
            }
        }

        // not found → create
        $label = $this->formatLabel($incQty, $incName);
        $items[] = $label;
        $this->session->put($this->key, $items);
        return $items;
    }

    /**
     * Decrement quantities on remove:
     *  - "remove two cucumbers" from "7 Cucumbers" → "5 Cucumbers"
     *  - "remove cucumbers" (no qty) defaults to 1
     *  - If qty >= current → remove item entirely
     *  - If no exact key match, uses Levenshtein fuzzy fallback
     */
    public function remove(string $item): array
    {
        $items = $this->all();
        if (empty($items)) return $items;

        [$decQty, $needleName] = $this->parseQtyAndName($item, defaultQty: 1);
        if ($needleName === '') return $items;

        $needleKey = $this->matchKey($needleName);

        // 1) Exact key match first
        foreach ($items as $idx => $existing) {
            [$curQty, $curName] = $this->splitQtyName($existing);
            if ($this->matchKey($curName) === $needleKey) {
                return $this->applyDecrement($items, $idx, $curQty, $curName, $decQty);
            }
        }

        // 2) Fuzzy fallback on key (case-insensitive, singularized)
        $bestIdx = $this->findClosestIndex($needleKey, $items);
        if ($bestIdx !== null) {
            [$curQty, $curName] = $this->splitQtyName($items[$bestIdx]);
            return $this->applyDecrement($items, $bestIdx, $curQty, $curName, $decQty);
        }

        return $items;
    }

    private function applyDecrement(array $items, int $idx, int $curQty, string $curName, int $decQty): array
    {
        $newQty = max(0, ($curQty ?: 1) - max(1, $decQty));
        if ($newQty === 0) {
            unset($items[$idx]);
            $items = array_values($items);
        } else {
            $items[$idx] = $this->formatLabel($newQty, $curName);
        }
        $this->session->put($this->key, $items);
        return $items;
    }

    // ----------------------- COMMAND PARSER -----------------------

    /**
     * Commands:
     *  - "add <ANY TEXT…>" → ONE item, merges quantities (digits or words; strips a/an)
     *  - "remove a, b and c" → may remove multiple; each may include qty (digits or words)
     *  - "clear list" / "delete list" / "new list" → empties list
     */
    public function processCommand(string $text): array
    {
        $raw = trim($text ?? '');

        // CLEAR
        if ($this->isClearCommand($raw)) {
            return ['action' => 'clear', 'items' => $this->clear()];
        }

        // ADD (single item only)
        if (preg_match('/^\s*(add|ed|yeah|and|plus|include)\s+(.+)$/iu', $raw, $m)) {
            $payload = $this->collapseSpaces($this->stripSurroundingQuotes($m[2]));
            $payload = $this->stripLeadingIndefiniteArticle($payload); // "a"/"an" -> qty 1
            if ($payload !== '') $this->add($payload);
            return ['action' => 'add', 'items' => $this->all()];
        }

        // REMOVE (supports multiple tokens)
        if (preg_match('/^\s*(remove|move to|move|removes|delete|minus|drop)\s+(.+)$/iu', $raw, $m)) {
            foreach ($this->splitItems($m[2]) as $p) $this->remove($p);
            return ['action' => 'remove', 'items' => $this->all()];
        }

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

    // ----------------------- NAME/QTY NORMALIZATION -----------------------

    /** Parse qty (digits or number-words or a/an) + cleaned name. */
    private function parseQtyAndName(string $raw, int $defaultQty = 1): array
    {
        $s = $this->collapseSpaces($this->stripPunctuation($raw));

        // a/an → 1
        if (preg_match('/^\s*(a|an)\s+(.+)$/iu', $s, $m)) {
            $qty = 1;
            $name = $m[2];
            return [$qty, $this->normalizeName($name)];
        }

        // digits
        if (preg_match('/^\s*(\d+)\s+(.+)$/u', $s, $m)) {
            $qty = (int)$m[1];
            $name = $m[2];
            return [max(1, $qty), $this->normalizeName($name)];
        }

        // number words ("six", "twenty one", "one hundred and two", with hyphens)
        [$wqty, $rest] = $this->extractLeadingWordNumber($s);
        if ($wqty !== null && $rest !== '') {
            return [max(1, $wqty), $this->normalizeName($rest)];
        }

        // no qty → default
        return [max(1, $defaultQty), $this->normalizeName($s)];
    }

    /** Clean and Title-Case the name (no qty). */
    private function normalizeName(string $name): string
    {
        $name = $this->cleanName($name);
        return $this->titleCaseItems ? $this->toTitle($name) : $name;
    }

    /** For matching: lowercased, last-word singularized, spaces collapsed. */
    private function matchKey(string $name): string
    {
        $low = mb_strtolower($this->cleanName($name));
        return $this->singularizePhrase($low);
    }

    /** Format label to display with correct plural for qty. */
    private function formatLabel(int $qty, string $name): string
    {
        $singularPhrase = $this->singularizePhrase(mb_strtolower($this->cleanName($name)));
        $display = $this->pluralizePhrase($singularPhrase, $qty);
        $display = $this->titleCaseItems ? $this->toTitle($display) : $display;
        return ($qty > 1 ? ($qty . ' ') : '') . $display;
    }

    /** Split an existing label like "3 Cucumbers" or "Cucumber" into [qty, name] */
    private function splitQtyName(string $label): array
    {
        $label = $this->collapseSpaces($label);
        // digits at start
        if (preg_match('/^\s*(\d+)\s+(.+)$/u', $label, $m)) {
            return [max(1, (int)$m[1]), $this->cleanName($m[2])];
        }
        // word-number at start
        [$wqty, $rest] = $this->extractLeadingWordNumber($label);
        if ($wqty !== null && $rest !== '') {
            return [max(1, $wqty), $this->cleanName($rest)];
        }
        // none → 1
        return [1, $this->cleanName($label)];
    }

    // ----------------------- WORD-NUMBER PARSER -----------------------

    /** Return [qty|null, rest] for leading number-words; supports up to thousands. */
    private function extractLeadingWordNumber(string $s): array
    {
        $orig = $s;
        $s = preg_replace('/[-]/u', ' ', $s); // hyphens → spaces
        $tokens = preg_split('/\s+/u', trim($s)) ?: [];

        if (!$tokens) return [null, $orig];

        $num = 0; $acc = 0; $consumed = 0;
        $mapUnits = [
            'zero'=>0,'one'=>1,'two'=>2,'three'=>3,'four'=>4,'five'=>5,'six'=>6,'seven'=>7,'eight'=>8,'nine'=>9,
            'ten'=>10,'eleven'=>11,'twelve'=>12,'thirteen'=>13,'fourteen'=>14,'fifteen'=>15,'sixteen'=>16,'seventeen'=>17,'eighteen'=>18,'nineteen'=>19,
        ];
        $mapTens = ['twenty'=>20,'thirty'=>30,'forty'=>40,'fifty'=>50,'sixty'=>60,'seventy'=>70,'eighty'=>80,'ninety'=>90];
        $scales = ['hundred'=>100,'thousand'=>1000];

        $i = 0; $used = false;
        while ($i < count($tokens)) {
            $w = mb_strtolower($tokens[$i]);
            if ($w === 'and') { $i++; $consumed++; continue; }

            if (isset($mapUnits[$w])) {
                $acc += $mapUnits[$w]; $used = true; $i++; $consumed++; continue;
            }
            if (isset($mapTens[$w])) {
                $acc += $mapTens[$w]; $used = true; $i++; $consumed++; continue;
            }
            if (isset($scales[$w])) {
                if ($acc === 0) $acc = 1; // "hundred" → 100
                $acc *= $scales[$w]; $used = true; $i++; $consumed++; continue;
            }
            break; // not a number word
        }

        if (!$used) return [null, $orig];

        $num = $acc;
        if ($num <= 0) return [null, $orig];

        $rest = implode(' ', array_slice($tokens, $consumed));
        return [$num, $rest ?: ''];
    }

    // ----------------------- PLURAL / SINGULAR (last word) -----------------------

    private function singularizePhrase(string $phrase): string
    {
        $parts = preg_split('/\s+/u', trim($phrase)) ?: [];
        if (!$parts) return $phrase;
        $last = $parts[count($parts)-1];
        $parts[count($parts)-1] = $this->singularizeWord($last);
        return implode(' ', $parts);
    }

    private function pluralizePhrase(string $singularPhrase, int $qty): string
    {
        if ($qty === 1) return $singularPhrase;
        $parts = preg_split('/\s+/u', trim($singularPhrase)) ?: [];
        if (!$parts) return $singularPhrase;
        $last = $parts[count($parts)-1];
        $parts[count($parts)-1] = $this->pluralizeWord($last);
        return implode(' ', $parts);
    }

    private function singularizeWord(string $w): string
    {
        // Common English rules (quick & dirty)
        if (preg_match('/(.*[^aeiou])ies$/u', $w, $m)) return $m[1].'y';        // parties -> party
        if (preg_match('/(.*)(ches|shes|xes|zes|ses)$/u', $w, $m)) return $m[1]; // boxes -> box, buses -> bus
        if (preg_match('/(.*)oes$/u', $w, $m)) return $m[1].'o';                 // tomatoes -> tomato
        if (preg_match('/(.*[^s])s$/u', $w, $m)) return $m[1];                   // cucumbers -> cucumber
        return $w;
    }

    private function pluralizeWord(string $w): string
    {
        if (preg_match('/(.*[^aeiou])y$/u', $w, $m)) return $m[1].'ies';         // party -> parties
        if (preg_match('/(.*)(ch|sh|x|z|s)$/u', $w))  return $w.'es';            // box -> boxes
        if (preg_match('/(.*)o$/u', $w))              return $w.'es';            // tomato -> tomatoes
        if (preg_match('/(.*)f$/u', $w, $m))         return $m[1].'ves';         // loaf -> loaves (approx)
        if (preg_match('/(.*)fe$/u', $w, $m))        return $m[1].'ves';         // knife -> knives
        return $w.'s';
    }

    // ----------------------- UTILITIES -----------------------

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

    private function stripLeadingIndefiniteArticle(string $s): string
    {
        return preg_replace('/^\s*(a|an)\s+/iu', '', $s) ?? $s;
    }

    private function collapseSpaces(string $s): string
    {
        return preg_replace('/\s+/u', ' ', trim($s)) ?? '';
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

    /** normalize for fuzzy matching: lowercase, clean, drop leading qty, singularize last word */
    private function normalizeForMatch(string $s): string
    {
        $s = mb_strtolower($this->cleanName($s));
        // drop leading digits or number-words
        if (preg_match('/^\d+\s+(.+)$/u', $s, $m)) $s = $m[1];
        else {
            [$wqty, $rest] = $this->extractLeadingWordNumber($s);
            if ($wqty !== null && $rest !== '') $s = $rest;
        }
        return $this->singularizePhrase($s);
    }

    private function findClosestIndex(string $needleKey, array $haystack): ?int
    {
        if (empty($haystack)) return null;
        $bestIdx = null; $bestDist = PHP_INT_MAX;
        foreach ($haystack as $idx => $item) {
            [$q, $name] = $this->splitQtyName($item);
            $cand = $this->matchKey($name);
            $dist = levenshtein($needleKey, $cand);
            if ($dist < $bestDist) { $bestDist = $dist; $bestIdx = $idx; if ($dist === 0) break; }
        }
        return $bestIdx;
    }
}
