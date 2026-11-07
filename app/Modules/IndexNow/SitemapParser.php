<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\IndexNow;

use XMLReader;

/**
 * Reads a sitemap (urlset) or sitemap index without loading external
 * entities or DTDs. Only http(s) locations are returned.
 */
final class SitemapParser
{
    private function __construct() {}

    /**
     * @return array{urls: list<array{loc: string, lastmod: ?string}>, sitemaps: list<string>}
     * @throws \InvalidArgumentException when the document is not a sitemap
     */
    public static function parse(string $xml): array
    {
        if (preg_match('/<!DOCTYPE/i', $xml) === 1) {
            throw new \InvalidArgumentException('The sitemap contains a DOCTYPE, which is not allowed.');
        }
        $reader = XMLReader::XML($xml, null, LIBXML_NONET);
        if (!$reader instanceof XMLReader) {
            throw new \InvalidArgumentException('The sitemap is not valid XML.');
        }
        $urls = [];
        $sitemaps = [];
        $root = null;
        $current = null;
        $loc = null;
        $lastmod = null;
        $previous = libxml_use_internal_errors(true);
        try {
            while (@$reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT) {
                    $name = $reader->localName;
                    $root ??= $name;
                    if ($name === 'url' || $name === 'sitemap') {
                        $current = $name;
                        $loc = $lastmod = null;
                    } elseif ($current !== null && ($name === 'loc' || $name === 'lastmod')) {
                        $value = trim($reader->readString());
                        $name === 'loc' ? $loc = $value : $lastmod = $value;
                    }
                } elseif ($reader->nodeType === XMLReader::END_ELEMENT && ($reader->localName === 'url' || $reader->localName === 'sitemap') && $current !== null) {
                    if ($loc !== null && preg_match('#^https?://#i', $loc) === 1 && strlen($loc) <= 2048) {
                        if ($current === 'url') {
                            $urls[] = ['loc' => $loc, 'lastmod' => $lastmod !== null && $lastmod !== '' ? mb_substr($lastmod, 0, 40) : null];
                        } else {
                            $sitemaps[] = $loc;
                        }
                    }
                    $current = null;
                }
            }
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if ($root !== 'urlset' && $root !== 'sitemapindex') {
            throw new \InvalidArgumentException('This is not a sitemap (expected <urlset> or <sitemapindex>).');
        }

        return ['urls' => $urls, 'sitemaps' => $sitemaps];
    }
}
