<?php

declare(strict_types=1);

namespace KidsNotices\AI\Privacy;

use KidsNotices\AI\HttpError;

/**
 * Replaces people's names with codes before anything is sent to Anthropic, removes contact
 * details and identifiers, and puts the real names back in the answer.
 *
 *   [S12] student 12   [P4] parent 4   [T7] teacher 7   [E3] other staff 3
 *   [N1]  a name part shared by several people (usually the family name)
 *
 * Matching tolerates Arabic spelling variants (أ/إ/آ/ا, ة/ه, ى/ي, ؤ/و, ئ/ي, diacritics, tatweel),
 * attached prefixes (و ف ب ل ك and combinations: «لياسين», «وبأحمد»), letter case and French accents.
 * Patterns are compiled in chunks to stay far below PCRE limits with large name lists.
 */
final class Pseudonymizer
{
    private const ROLE_LETTERS = ['student' => 'S', 'parent' => 'P', 'teacher' => 'T'];

    /** Name particles that are merged with the following word: «عبد الله», «Ben Ali», «آيت باها»… */
    private const PARTICLES = ['عبد', 'ابو', 'ابن', 'بن', 'بنت', 'ايت', 'مولاي', 'سيدي', 'لالة', 'لاله', 'ال',
        'abd', 'abdel', 'abou', 'abu', 'ben', 'bent', 'bin', 'ibn', 'ait', 'el', 'al', 'moulay', 'sidi', 'lalla', 'ou', 'oulad', 'ould'];

    private const CHUNK_SIZE = 40;

    /** Keys whose values are dropped entirely (contacts, official identifiers): substrings… */
    private const DROP_KEY_PARTS = '/(phone|email|courriel|whatsapp|massar|passport|passeport|adresse|address|هاتف|بريد|عنوان|البطاقة|رمز_مسار)/u';

    /** …and whole key segments (too short to match as substrings). */
    private const DROP_KEY_SEGMENTS = '/(^|_)(tel|mobile|gsm|mail|cin|cne|iban|rib|nid|national_id)(_|$)/u';

    /** Keys holding a birth date: replaced by the age in years. */
    private const BIRTH_KEYS = '/(birth|naissance|dob|ازدياد|الازدياد|الولادة|ميلاد)/u';

    /** Request keys never pseudonymized (platform data, not personal data). */
    private const SKIP_KEYS = ['people', 'pseudonymize', 'school_name', 'pages', 'role', 'language', 'languages', 'kind', 'audience', 'tone', 'to', 'difficulty', 'count'];

    /** @var array<string, string> code => real name */
    private array $names = [];

    /** @var array<string, string> normalized variant => code */
    private array $variants = [];

    /** @var list<string> */
    private array $patterns = [];

    /** @param list<array{id: mixed, name: mixed, role?: mixed}> $people */
    public static function fromPeople(array $people): self
    {
        $self = new self();
        $persons = [];
        foreach (array_values($people) as $i => $p) {
            $id = is_scalar($p['id'] ?? null) ? preg_replace('/[^A-Za-z0-9_-]/', '', (string) $p['id']) : '';
            $name = is_string($p['name'] ?? null) ? trim(preg_replace('/\s+/u', ' ', $p['name'])) : '';
            if ($id === '' || $name === '' || mb_strlen($name) > 120) {
                throw new HttpError(400, 'invalid_input', "people[$i] must have an id (letters/digits) and a name.");
            }
            $code = '[' . (self::ROLE_LETTERS[$p['role'] ?? ''] ?? 'E') . $id . ']';
            $self->names[$code] = $name;
            $persons[] = ['code' => $code, 'name' => $name, 'parts' => self::parts($name)];
        }

        // Which name parts belong to a single person, which are shared (family names)?
        $owners = [];
        foreach ($persons as $person) {
            foreach (array_unique(array_map([self::class, 'normalize'], $person['parts'])) as $part) {
                $owners[$part][$person['code']] = true;
            }
        }

        $shared = 0;
        foreach ($persons as $person) {
            $self->variants[self::normalize($person['name'])] ??= $person['code'];
            foreach ($person['parts'] as $part) {
                $key = self::normalize($part);
                if (mb_strlen($key) < 3 || isset($self->variants[$key])) {
                    continue;
                }
                if (count($owners[$key]) === 1) {
                    $self->variants[$key] = $person['code'];
                } else {
                    $code = '[N' . (++$shared) . ']';
                    $self->names[$code] = $part;
                    $self->variants[$key] = $code;
                }
            }
        }

        // Longest variants first so a full name wins over its parts.
        $keys = array_keys($self->variants);
        usort($keys, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        foreach (array_chunk($keys, self::CHUNK_SIZE) as $chunk) {
            $self->patterns[] = '/(?<![\p{L}\p{N}])((?:وب|ول|وك|فب|فل|و|ف|ب|ل|ك)?)(' . implode('|', array_map([self::class, 'pattern'], $chunk)) . ')(?![\p{L}\p{N}])/iu';
        }
        return $self;
    }

    /** Applies the pseudonymization to a whole request body. */
    public function scrubRequest(array $body): array
    {
        foreach ($body as $key => $value) {
            if (!in_array($key, self::SKIP_KEYS, true)) {
                $body[$key] = $this->scrub($value);
            }
        }
        return $body;
    }

    public function scrub(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->scrubText($value);
        }
        if (!is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                // "phoneNumber", "date-of-birth" → "phone_number", "date_of_birth"
                $lower = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '_', preg_replace('/(?<=\p{Ll})(?=\p{Lu})/u', '_', $key)));
                if (preg_match(self::DROP_KEY_PARTS, $lower) || preg_match(self::DROP_KEY_SEGMENTS, $lower)) {
                    continue;
                }
                if (preg_match(self::BIRTH_KEYS, $lower)) {
                    $age = self::age($item);
                    if ($age !== null) {
                        $out['age'] = $age;
                    }
                    continue;
                }
            }
            $out[$key] = $this->scrub($item);
        }
        return $out;
    }

    public function scrubText(string $text): string
    {
        // Contacts and identifiers, even when not in a dedicated field.
        $text = preg_replace('/[\p{L}0-9._%+-]+@[\p{L}0-9-]+(?:\.[\p{L}0-9-]+)+/u', '[email]', $text);
        $text = preg_replace('/(?<![\d+])(?:\+|00)212[\s.\-]*\(?0?\)?[\s.\-]*[5-8](?:[\s.\-]*\d){8}(?!\d)|(?<![\d+])0[5-8](?:[\s.\-]*\d){8}(?!\d)|(?<![\d+])\+\d{1,3}(?:[\s.\-]*\d){6,12}(?!\d)/u', '[phone]', $text);
        $text = preg_replace('/(?<![\p{L}\p{N}])[A-Za-z]\d{9}(?![\p{L}\p{N}])/u', '[massar]', $text);
        $text = preg_replace('/(?<![\p{L}\p{N}])[A-Za-z]{1,2}\d{5,6}(?![\p{L}\p{N}])/u', '[id]', $text);

        foreach ($this->patterns as $pattern) {
            $text = preg_replace_callback($pattern, function (array $m): string {
                $code = $this->variants[self::normalize($m[2])] ?? null;
                return $code === null ? $m[0] : $m[1] . $code;
            }, $text);
            if ($text === null) {
                // Never send text we could not clean.
                throw new HttpError(500, 'privacy_error', 'Pseudonymization failed: ' . preg_last_error_msg());
            }
        }
        return $text;
    }

    /** Puts the real names back (strings and nested arrays). */
    public function restore(mixed $value): mixed
    {
        if (is_string($value)) {
            return preg_replace_callback('/\[([SPTEN][A-Za-z0-9_-]+)\]/', fn ($m) => $this->names['[' . $m[1] . ']'] ?? $m[0], $value);
        }
        if (is_array($value)) {
            return array_map(fn ($v) => $this->restore($v), $value);
        }
        return $value;
    }

    /** Paragraph appended to the system prompt. */
    public function systemNote(): string
    {
        return <<<NOTE

Privacy: people's names were replaced by codes before reaching you: [S…] a student, [P…] a parent,
[T…] a teacher, [E…] another staff member, [N…] a family name shared by several people.
Contact details and identifiers were removed ([phone], [email], [massar], [id]).
Refer to people only with these codes, copied exactly with their brackets (e.g. [S12]); you may attach
Arabic prefixes directly before a code (لـ[S12]). Never guess, invent or ask for real names.
The platform replaces the codes with the real names before showing your answer.
NOTE;
    }

    /** @return list<string> name parts, particles merged with the following word */
    private static function parts(string $name): array
    {
        $words = explode(' ', $name);
        $parts = [];
        for ($i = 0; $i < count($words); $i++) {
            $word = $words[$i];
            while (in_array(self::normalize($word), self::PARTICLES, true) && isset($words[$i + 1])) {
                $word .= ' ' . $words[++$i];
            }
            $parts[] = $word;
        }
        return $parts;
    }

    public static function normalize(string $s): string
    {
        $s = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', mb_strtolower($s)); // diacritics, tatweel
        $s = strtr($s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ة' => 'ه', 'ى' => 'ي', 'ئ' => 'ي', 'ؤ' => 'و',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'É' => 'e', 'È' => 'e', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'À' => 'a',
            'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'Ç' => 'c']);
        return trim(preg_replace('/[\s\-]+/u', ' ', $s));
    }

    /** Regex for a normalized variant that also matches the spelling variants. */
    private static function pattern(string $normalized): string
    {
        static $classes = [
            'ا' => '[اأإآٱ]', 'ه' => '[هة]', 'ي' => '[يىئ]', 'و' => '[وؤ]',
            'e' => '[eéèêë]', 'a' => '[aàâä]', 'i' => '[iîï]', 'o' => '[oôö]', 'u' => '[uùûü]', 'c' => '[cç]',
            ' ' => '[\s\-]+',
        ];
        $out = '';
        foreach (mb_str_split($normalized) as $char) {
            $out .= $classes[$char] ?? preg_quote($char, '/');
            if (preg_match('/\p{Arabic}/u', $char)) {
                $out .= '[\x{064B}-\x{065F}\x{0670}\x{0640}]*';
            }
        }
        return $out;
    }

    private static function age(mixed $date): ?int
    {
        if (!is_string($date) || ($ts = strtotime($date)) === false || $ts > time()) {
            return null;
        }
        return (int) (new \DateTimeImmutable('@' . $ts))->diff(new \DateTimeImmutable())->y;
    }
}
