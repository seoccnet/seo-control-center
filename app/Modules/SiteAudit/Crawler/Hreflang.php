<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteAudit\Crawler;

/**
 * Hreflang language codes (1.2.0): an ISO 639-1 language, optionally a
 * script (zh-Hant) and an ISO 3166-1 alpha-2 region (en-GB), or x-default.
 * Google ignores codes it cannot read, so a wrong code silently drops that
 * language version.
 */
final class Hreflang
{
    public const int MAX_LINKS = 50;
    public const string DEFAULT = 'x-default';

    private const string LANGUAGES = 'aa ab ae af ak am an ar as av ay az ba be bg bh bi bm bn bo br bs ca ce ch co cr cs cu cv cy da de dv dz ee el en eo es et eu fa ff fi fj fo fr fy ga gd gl gn gu gv ha he hi ho hr ht hu hy hz ia id ie ig ii ik io is it iu ja jv ka kg ki kj kk kl km kn ko kr ks ku kv kw ky la lb lg li ln lo lt lu lv mg mh mi mk ml mn mr ms mt my na nb nd ne ng nl nn no nr nv ny oc oj om or os pa pi pl ps pt qu rm rn ro ru rw sa sc sd se sg si sk sl sm sn so sq sr ss st su sv sw ta te tg th ti tk tl tn to tr ts tt tw ty ug uk ur uz ve vi vo wa wo xh yi yo za zh zu';
    private const string REGIONS = 'ad ae af ag ai al am ao aq ar as at au aw ax az ba bb bd be bf bg bh bi bj bl bm bn bo bq br bs bt bv bw by bz ca cc cd cf cg ch ci ck cl cm cn co cr cu cv cw cx cy cz de dj dk dm do dz ec ee eg eh er es et fi fj fk fm fo fr ga gb gd ge gf gg gh gi gl gm gn gp gq gr gs gt gu gw gy hk hm hn hr ht hu id ie il im in io iq ir is it je jm jo jp ke kg kh ki km kn kp kr kw ky kz la lb lc li lk lr ls lt lu lv ly ma mc md me mf mg mh mk ml mm mn mo mp mq mr ms mt mu mv mw mx my mz na nc ne nf ng ni nl no np nr nu nz om pa pe pf pg ph pk pl pm pn pr ps pt pw py qa re ro rs ru rw sa sb sc sd se sg sh si sj sk sl sm sn so sr ss st sv sx sy sz tc td tf tg th tj tk tl tm tn to tr tt tv tw tz ua ug um us uy uz va vc ve vg vi vn vu wf ws ye yt za zm zw';
    /** Common mistakes and the code meant. */
    private const array FIXES = ['uk' => 'GB'];

    /**
     * Why a code is wrong, or null when Google can read it.
     */
    public static function problem(string $code): ?string
    {
        $code = strtolower(trim($code));
        if ($code === self::DEFAULT) {
            return null;
        }
        if (preg_match('/^([a-z]{2,3})(?:[-_]([a-z]{4}))?(?:[-_]([a-z]{2}|\d{3}))?$/', $code, $m) !== 1) {
            return sprintf('"%s" is not a language code (use ISO 639-1, such as de or en-GB)', self::short($code));
        }
        if (str_contains($code, '_')) {
            return sprintf('"%s" uses an underscore; use a hyphen (%s)', $code, str_replace('_', '-', $code));
        }
        $language = $m[1];
        $region = $m[3] ?? '';
        if (!in_array($language, self::list(self::LANGUAGES), true)) {
            // A region where the language belongs (us-en, gb-en) is the commonest slip.
            if (in_array($language, self::list(self::REGIONS), true) && in_array($region, self::list(self::LANGUAGES), true)) {
                return sprintf('"%s" puts the region first; use %s-%s', $code, $region, strtoupper($language));
            }

            return sprintf('"%s" is not an ISO 639-1 language', $language);
        }
        if ($region !== '' && !ctype_digit($region) && !in_array($region, self::list(self::REGIONS), true)) {
            return match (true) {
                isset(self::FIXES[$region]) => sprintf('"%s" is not a country code; use %s-%s', $code, $language, self::FIXES[$region]),
                $region === 'eu' => sprintf('"%s": EU is not a country; hreflang regions are single countries', $code),
                default => sprintf('"%s" is not an ISO 3166-1 country', strtoupper($region)),
            };
        }

        return null;
    }

    /** The language part of a code, such as "en" for en-GB; null for x-default. */
    public static function language(string $code): ?string
    {
        $code = strtolower(trim($code));
        if ($code === self::DEFAULT) {
            return null;
        }

        return (string) preg_replace('/[-_].*$/', '', $code);
    }

    /** @return list<string> */
    private static function list(string $codes): array
    {
        static $cache = [];

        return $cache[$codes] ??= explode(' ', $codes);
    }

    private static function short(string $code): string
    {
        return mb_strlen($code) > 30 ? mb_substr($code, 0, 30) . '…' : $code;
    }
}
