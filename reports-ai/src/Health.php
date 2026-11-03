<?php

declare(strict_types=1);

namespace ReportsAI;

/**
 * Connection checks shown in the settings page: the file-reading service on your computer, the local
 * model (Ollama), and what this server can read by itself.
 */
final class Health
{
    /** @return array{ok: bool, message: string, pdf?: bool, ocr?: bool, office?: bool} */
    public static function extractor(?string $url = null, ?string $key = null): array
    {
        $url ??= Config::get('RAI_EXTRACT_URL');
        $key ??= Config::get('RAI_EXTRACT_KEY');
        if ($url === null) {
            return ['ok' => false, 'message' => 'غير مضبوطة'];
        }
        [$status, $data] = self::getJson(rtrim($url, '/') . '/health', ['Authorization: Bearer ' . ($key ?? '')]);
        if ($status === 200 && !empty($data['ok'])) {
            return ['ok' => true, 'message' => 'تعمل', 'pdf' => !empty($data['pdf']), 'ocr' => !empty($data['ocr']), 'office' => !empty($data['office'])];
        }
        return ['ok' => false, 'message' => self::failure($status, 'تأكد أن نافذة الخدمة مفتوحة على حاسوبك وأن العنوان هو المعروض فيها.')];
    }

    /** @return array{ok: bool, message: string, models?: list<string>} */
    public static function local(?string $url = null, ?string $key = null): array
    {
        $url ??= Config::get('RAI_LOCAL_URL', 'http://127.0.0.1:11434');
        $key ??= Config::get('RAI_LOCAL_KEY');
        $headers = [];
        if ($key !== null && $key !== '') {
            $name = Config::get('RAI_LOCAL_KEY_HEADER', 'Authorization');
            $headers[] = strcasecmp($name, 'Authorization') === 0 ? "Authorization: Bearer $key" : "$name: $key";
        }
        [$status, $data] = self::getJson(rtrim((string) $url, '/') . '/api/tags', $headers);
        if ($status === 200 && is_array($data['models'] ?? null)) {
            $models = array_values(array_filter(array_column($data['models'], 'name'), 'is_string'));
            return ['ok' => true, 'message' => 'متصل', 'models' => $models];
        }
        return ['ok' => false, 'message' => self::failure($status, 'تأكد أن الحاسوب وOllama والقناة تعمل، وأن العنوان صحيح.')];
    }

    /** What this server reads without help. */
    public static function hosting(): array
    {
        return ['pdf' => Pdf::available(), 'ocr' => Pdf::ocrAvailable(), 'office' => Documents::soffice() !== null];
    }

    private static function failure(int $status, string $hint): string
    {
        return match (true) {
            $status === 401 || $status === 403 => 'المفتاح غير صحيح.',
            $status === 0 => 'لا تجيب. ' . $hint,
            default => "خطأ HTTP $status. " . $hint,
        };
    }

    /** @return array{0: int, 1: mixed} */
    private static function getJson(string $url, array $headers): array
    {
        if (!preg_match('#^https?://#i', $url)) {
            return [0, null];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20]);
        $body = curl_exec($ch);
        $status = $body === false ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$status, is_string($body) ? json_decode($body, true) : null];
    }
}
