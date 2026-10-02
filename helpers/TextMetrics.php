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
