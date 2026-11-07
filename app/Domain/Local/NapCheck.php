<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Local;

/**
 * Whether the website states the same business as the Google Business
 * Profile (0.46.0): LocalBusiness markup, name, phone, address and the
 * profile's website. Compared against the business details the latest
 * site audit read from the crawled pages.
 *
 * Each field is ok, mismatch, missing (the site does not state it) or
 * unknown (nothing to compare: no audit since 0.46.0, or the profile
 * does not show the field).
 */
final readonly class NapCheck
{
    public const string OK = 'ok';
    public const string MISMATCH = 'mismatch';
    public const string MISSING = 'missing';
    public const string UNKNOWN = 'unknown';

    /** schema.org LocalBusiness and its common subtypes. */
    public const array LOCAL_TYPES = [
        'LocalBusiness', 'Store', 'ProfessionalService', 'HomeAndConstructionBusiness', 'HealthAndBeautyBusiness',
        'MedicalBusiness', 'MedicalClinic', 'Dentist', 'Physician', 'Pharmacy', 'Optician', 'AutomotiveBusiness',
        'AutoRepair', 'AutoDealer', 'FoodEstablishment', 'Restaurant', 'CafeOrCoffeeShop', 'Bakery', 'BarOrPub',
        'LodgingBusiness', 'Hotel', 'LegalService', 'Attorney', 'Notary', 'FinancialService', 'AccountingService',
        'InsuranceAgency', 'RealEstateAgent', 'SportsActivityLocation', 'ExerciseGym', 'EntertainmentBusiness',
        'ChildCare', 'EmploymentAgency', 'TravelAgency', 'SelfStorage', 'HairSalon', 'BeautySalon', 'DaySpa',
        'Plumber', 'Electrician', 'HVACBusiness', 'RoofingContractor', 'GeneralContractor', 'Locksmith',
        'MovingCompany', 'PetStore', 'ClothingStore', 'ElectronicsStore', 'FurnitureStore', 'GardenStore',
        'GroceryStore', 'HardwareStore', 'JewelryStore', 'BookStore', 'ShoeStore', 'SportingGoodsStore',
        'ToyStore', 'Florist', 'ConvenienceStore', 'DepartmentStore', 'HomeGoodsStore', 'MobilePhoneStore',
        'LiquorStore', 'BikeStore', 'ComputerStore', 'HobbyShop', 'OfficeEquipmentStore', 'TireShop',
    ];

    /**
     * @param array<string, string> $fields field (markup, name, phone, address, website) => status
     * @param array{types: list<string>, name: ?string, phones: list<string>, address: ?string}|null $site what the site states
     */
    public function __construct(
        public array $fields,
        public ?array $site,
        public ?string $pageUrl,
    ) {}

    /**
     * @param list<array{url: string, business: array{types: list<string>, name: ?string, phones: list<string>, address: ?string}|null}>|null $pages
     *        pages of the latest completed site audit; null without one
     */
    public static function compare(BusinessProfile $profile, ?string $domain, ?array $pages): self
    {
        $fields = ['website' => self::website($profile->url, $domain)];
        $read = array_values(array_filter($pages ?? [], static fn(array $p): bool => $p['business'] !== null));
        if ($read === []) {
            return new self($fields + ['markup' => self::UNKNOWN, 'name' => self::UNKNOWN, 'phone' => self::UNKNOWN, 'address' => self::UNKNOWN], null, null);
        }

        // The page that describes the business best: local markup first, then any address or name.
        $best = null;
        $bestScore = -1;
        $phones = [];
        foreach ($read as $p) {
            $b = $p['business'];
            array_push($phones, ...($b['phones'] ?? []));
            $score = (array_intersect($b['types'] ?? [], self::LOCAL_TYPES) !== [] ? 4 : 0) + ($b['address'] !== null ? 2 : 0) + ($b['name'] !== null ? 1 : 0);
            if ($score > $bestScore) {
                [$best, $bestScore] = [$p, $score];
            }
        }
        $site = $best['business'] ?? null;
        $site = $site !== null ? ['types' => $site['types'], 'name' => $site['name'], 'phones' => array_values(array_unique($phones)), 'address' => $site['address']] : null;
        $stated = $site !== null && $site['types'] !== [];

        $fields['markup'] = $site !== null && array_intersect($site['types'], self::LOCAL_TYPES) !== [] ? self::OK : self::MISSING;
        $fields['name'] = !$stated || $site['name'] === null ? self::MISSING : (self::sameName($profile->title, $site['name']) ? self::OK : self::MISMATCH);
        $fields['phone'] = match (true) {
            $profile->phone === null || self::tail($profile->phone) === '' => self::UNKNOWN,
            $site === null || $site['phones'] === [] => self::MISSING,
            in_array(self::tail($profile->phone), array_map(self::tail(...), $site['phones']), true) => self::OK,
            default => self::MISMATCH,
        };
        $fields['address'] = match (true) {
            $profile->address === null || trim($profile->address) === '' => self::UNKNOWN,
            !$stated || $site['address'] === null => self::MISSING,
            self::sameAddress($profile->address, $site['address']) => self::OK,
            default => self::MISMATCH,
        };

        return new self($fields, $site, $best['url'] ?? null);
    }

    public function status(string $field): string
    {
        return $this->fields[$field] ?? self::UNKNOWN;
    }

    /** Whether anything was compared against the site at all. */
    public function isKnown(): bool
    {
        return $this->site !== null;
    }

    /** @return list<string> fields that differ from the profile or are not on the site */
    public function problems(): array
    {
        return array_keys(array_filter($this->fields, static fn(string $s): bool => $s === self::MISMATCH || $s === self::MISSING));
    }

    private static function website(?string $url, ?string $domain): string
    {
        if ($url === null || trim($url) === '') {
            return self::MISSING;
        }
        $host = strtolower((string) parse_url(str_contains($url, '://') ? $url : 'https://' . $url, PHP_URL_HOST));
        $host = (string) preg_replace('/^www\./', '', $host);
        if ($domain === null || $host === '') {
            return self::UNKNOWN;
        }
        $domain = (string) preg_replace('/^www\./', '', strtolower($domain));

        return $host === $domain || str_ends_with($host, '.' . $domain) || str_ends_with($domain, '.' . $host) ? self::OK : self::MISMATCH;
    }

    /** The last 8 digits: the same number written with or without the country code. */
    private static function tail(string $phone): string
    {
        return substr((string) preg_replace('/\D+/', '', $phone), -8);
    }

    private static function sameName(string $a, string $b): bool
    {
        $norm = static fn(string $s): string => (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($s));
        [$a, $b] = [$norm($a), $norm($b)];

        return $a !== '' && $b !== '' && (str_contains($a, $b) || str_contains($b, $a));
    }

    /** Same house number and postal code (the numbers in the site's address all appear in the profile's), or the same words. */
    private static function sameAddress(string $profile, string $site): bool
    {
        $numbers = static function (string $s): array {
            preg_match_all('/\d+/', $s, $m);
            return array_values(array_unique($m[0]));
        };
        [$p, $s] = [$numbers($profile), $numbers($site)];
        if ($p !== [] && $s !== []) {
            return array_diff($s, $p) === [];
        }
        $words = static fn(string $v): array => array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($v)) ?: [], static fn(string $w): bool => mb_strlen($w) > 2));

        return $words($site) !== [] && array_diff($words($site), $words($profile)) === [];
    }
}
