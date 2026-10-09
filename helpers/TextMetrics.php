<?php

declare(strict_types=1);

class TextMetrics
{
    public static function persianWordCount(?string $text): int
    {
        $text = self::normalize($text);

        if ($text === '') {
            return 0;
        }

        if (!preg_match_all('/[\p{L}\p{N}\x{200C}]+/u', $text, $matches)) {
            return 0;
        }

        return count($matches[0]);
    }

    public static function sentenceCount(?string $text): int
    {
        $text = self::normalize($text);

        if ($text === '') {
            return 0;
        }

        $parts = preg_split('/[.!?؟…]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        if ($parts === false) {
            return 0;
        }

        $count = 0;

        foreach ($parts as $part) {
            if (trim($part) !== '') {
                $count++;
            }
        }

        return $count;
    }

    public static function charCount(?string $text): int
    {
        $text = self::normalize($text);

        if ($text === '') {
            return 0;
        }

        return mb_strlen($text, 'UTF-8');
    }

    public static function analyze(?string $text): array
    {
        $normalized = self::normalize($text);

        return [
            'word_count' => self::persianWordCount($normalized),
            'sentence_count' => self::sentenceCount($normalized),
            'char_count' => self::charCount($normalized),
        ];
    }

    /**
     * Normalize a keyword/phrase for inventory matching (Persian yeh/kaf, case, whitespace).
     */
    public static function normalizeKeyword(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags($text);
        $text = str_replace(
            ["\u{064A}", "\u{0643}", "\u{0629}", "\u{200C}"],
            ["\u{06CC}", "\u{06A9}", "\u{0647}", ' '],
            $text
        );
        // Also handle literal Arabic yeh/kaf if encoded as UTF-8 bytes already matched above;
        // keep explicit byte-safe replacements for common forms:
        $text = str_replace(['ي', 'ك', 'ة'], ['ی', 'ک', 'ه'], $text);
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        return trim($text);
    }

    /**
     * Fuzzy keyword equality: exact normalized match, or one contains the other (min length 4).
     */
    public static function keywordsMatch(?string $a, ?string $b): bool
    {
        $na = self::normalizeKeyword($a);
        $nb = self::normalizeKeyword($b);

        if ($na === '' || $nb === '') {
            return false;
        }

        if ($na === $nb) {
            return true;
        }

        $minLen = 4;
        if (mb_strlen($na, 'UTF-8') < $minLen || mb_strlen($nb, 'UTF-8') < $minLen) {
            return false;
        }

        return str_contains($na, $nb) || str_contains($nb, $na);
    }

    /**
     * Whether haystack text contains the keyword (normalized substring).
     */
    public static function textContainsKeyword(?string $haystack, ?string $keyword): bool
    {
        $nHay = self::normalizeKeyword($haystack);
        $nKey = self::normalizeKeyword($keyword);

        if ($nHay === '' || $nKey === '') {
            return false;
        }

        return str_contains($nHay, $nKey);
    }

    private static function normalize(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags($text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        return trim($text);
    }
}
