<?php

declare(strict_types=1);

namespace ReportsAI;

/**
 * Text cleaning and Arabic/French normalization for indexing and search.
 */
final class Text
{
    private const STOPWORDS = [
        'في', 'من', 'على', 'الى', 'عن', 'مع', 'هذا', 'هذه', 'ذلك', 'التي', 'الذي', 'كان', 'كانت', 'او', 'ما', 'ماذا', 'كم', 'هل', 'لا', 'ثم', 'كل', 'بين', 'خلال', 'سنه', 'سنوات', 'عدد',
        'le', 'la', 'les', 'de', 'des', 'du', 'un', 'une', 'et', 'en', 'au', 'aux', 'pour', 'par', 'sur', 'dans', 'est', 'sont', 'quel', 'quelle', 'quels', 'combien', 'entre', 'annee', 'annees',
        'the', 'of', 'and', 'in', 'how', 'many', 'what', 'year', 'years',
    ];

    /** Removes bidi control characters and normalizes whitespace (keeps line structure). */
    public static function clean(string $text): string
    {
        $text = preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $text);
        $text = str_replace(["\u{00A0}", "\u{202F}", "\u{2009}"], ' ', $text);
        $text = preg_replace("/[ \t]+\n/", "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        return trim($text);
    }

    /** Lower-case, no diacritics, unified Arabic letters, light prefix stripping: used for the FTS index and queries. */
    public static function normalize(string $text): string
    {
        $text = mb_strtolower(self::clean($text));
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text);
        $text = strtr($text, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ة' => 'ه', 'ى' => 'ي', 'ئ' => 'ي', 'ؤ' => 'و',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'î' => 'i', 'ï' => 'i',
            'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'œ' => 'oe', '’' => "'",
        ]);
        $words = preg_split('/[^\p{L}\p{N}]+/u', preg_replace("/\b[ldjmnst]'/u", '', $text), -1, PREG_SPLIT_NO_EMPTY);
        return implode(' ', array_map([self::class, 'stem'], $words));
    }

    /** Strips the Arabic article and attached conjunction/prepositions in front of it (والرخص → رخص). */
    private static function stem(string $word): string
    {
        if (mb_strlen($word) > 4 && preg_match('/^(?:وال|بال|كال|فال|لل|ال)(.{3,})$/u', $word, $m)) {
            return $m[1];
        }
        return $word;
    }

    /** FTS5 MATCH expression: significant words, OR-ed, with prefix matching (رخص* also finds رخصه). */
    public static function ftsQuery(string $query): ?string
    {
        $terms = [];
        foreach (explode(' ', self::normalize($query)) as $word) {
            if ($word === '' || in_array($word, self::STOPWORDS, true) || (mb_strlen($word) < 3 && !ctype_digit($word))) {
                continue;
            }
            $terms[$word] = '"' . str_replace('"', '', $word) . '"' . (mb_strlen($word) >= 4 ? '*' : '');
        }
        return $terms ? implode(' OR ', $terms) : null;
    }

    /**
     * Some PDF tools (pdftotext included) output each lam-alef ligature with its letters swapped:
     * «خلال» → «خالل», «الاختبار» → «االختبار». Such text contains no «لا» at all, which normal
     * Arabic always does. Returns true when the text looks affected.
     */
    public static function hasSwappedLamAlef(string $text): bool
    {
        $arabicWords = preg_match_all('/\p{Arabic}{2,}/u', $text);
        return $arabicWords >= 80 && preg_match_all('/ل[اأإآ]/u', $text) === 0;
    }

    /** What the faulty extraction does: every lam + alef becomes alef + lam. */
    public static function swapLamAlef(string $text): string
    {
        return preg_replace('/ل([اأإآ])/u', '$1ل', $text);
    }

    /** A repaired page is accepted only if swapping back gives exactly the extracted text (spaces aside). */
    public static function isSafeLamAlefRepair(string $original, string $repaired): bool
    {
        $squash = fn (string $s) => preg_replace('/\s+/u', '', $s);
        return $squash(self::swapLamAlef($repaired)) === $squash($original);
    }

    /** Digits of a number as written in a text ("1 240", "1.240", "1240,5" → "1240" / "12405"). */
    public static function digits(string $s): string
    {
        return preg_replace('/\D/', '', strtr($s, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']));
    }
}
