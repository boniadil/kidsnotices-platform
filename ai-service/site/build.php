<?php

/*
 * Builds the public landing page of the service in Arabic, French and English.
 *
 *   php site/build.php                              (base URL: https://ai.kidsnotices.ma)
 *   KN_SITE_URL=https://example.com php site/build.php
 *
 * Writes site/dist/{ar,fr,en}.html (served by public/index.php at /, /fr and /en),
 * public/sitemap.xml and public/robots.txt. Edit the texts in site/content/<lang>.php, then rebuild.
 */

declare(strict_types=1);

$base = rtrim(getenv('KN_SITE_URL') ?: 'https://ai.kidsnotices.ma', '/');
$mainSite = 'https://kidsnotices.ma/';
$root = dirname(__DIR__);

$all = [];
foreach (['ar', 'fr', 'en'] as $lang) {
    $all[$lang] = require __DIR__ . "/content/$lang.php";
}

@mkdir(__DIR__ . '/dist', 0775, true);
foreach (array_keys($all) as $code) {
    $c = $all[$code];
    ob_start();
    require __DIR__ . '/template.php';
    $html = ob_get_clean();
    file_put_contents(__DIR__ . "/dist/$code.html", $html);
    printf("site/dist/%s.html  %5.1f KB  title %d chars, description %d chars\n", $code, strlen($html) / 1024, mb_strlen($c['title']), mb_strlen($c['description']));
}

// Sitemap with hreflang alternates, so search engines index each language version.
$today = gmdate('Y-m-d');
$xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
    . "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\" xmlns:xhtml=\"http://www.w3.org/1999/xhtml\">\n";
foreach ($all as $c) {
    $xml .= "  <url>\n    <loc>{$base}{$c['path']}</loc>\n    <lastmod>$today</lastmod>\n";
    foreach ($all as $alt) {
        $xml .= "    <xhtml:link rel=\"alternate\" hreflang=\"{$alt['lang']}\" href=\"{$base}{$alt['path']}\"/>\n";
    }
    $xml .= "    <xhtml:link rel=\"alternate\" hreflang=\"x-default\" href=\"{$base}/\"/>\n  </url>\n";
}
$xml .= "</urlset>\n";
file_put_contents("$root/public/sitemap.xml", $xml);

file_put_contents("$root/public/robots.txt", "User-agent: *\nAllow: /\nDisallow: /v1/\nDisallow: /dashboard\n\nSitemap: $base/sitemap.xml\n");

echo "public/sitemap.xml, public/robots.txt written for $base\n";
