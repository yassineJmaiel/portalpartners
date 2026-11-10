<?php

namespace App\Portal;

use Illuminate\Support\Facades\Cookie;

/* English is the source text; French and Arabic live in resources/dict. A person's
   saved language wins after sign-in; before it, the cookie the sign-in page sets. */
class Lang
{
    public const AVAILABLE = ['en', 'fr', 'ar'];

    private const LOCALES = ['en' => 'en_GB', 'fr' => 'fr_FR', 'ar' => 'ar_TN@numbers=latn'];

    private static ?string $current = null;

    private static array $dict = [];

    public static function set(?string $lang): void
    {
        self::$current = in_array($lang, self::AVAILABLE, true) ? $lang : 'en';
    }

    public static function current(): string
    {
        if (self::$current === null) {
            self::set(request()->cookie('vpp_lang') ?: 'en');
        }

        return self::$current;
    }

    public static function dir(): string
    {
        return self::current() === 'ar' ? 'rtl' : 'ltr';
    }

    public static function remember(string $lang): void
    {
        self::set($lang);
        Cookie::queue(Cookie::make('vpp_lang', self::current(), 60 * 24 * 365, null, null, null, true, false, 'Lax'));
    }

    private static function dict(string $lang): array
    {
        if (! isset(self::$dict[$lang])) {
            $file = resource_path("dict/{$lang}.json");
            self::$dict[$lang] = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
        }

        return self::$dict[$lang];
    }

    public static function t(?string $text, array $vars = []): string
    {
        $text = (string) $text;
        $lang = self::current();
        $s = $lang === 'en' ? $text : (self::dict($lang)[$text] ?? $text);
        foreach ($vars as $k => $v) {
            $s = str_replace('{'.$k.'}', (string) $v, $s);
        }

        return $s;
    }

    public static function date(int $ts, string $pattern): string
    {
        return (string) (new \IntlDateFormatter(self::LOCALES[self::current()], \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'UTC', null, $pattern))->format($ts);
    }

    /** sentences the servers write are stored in English; read back in the language on screen, one rule per sentence */
    public static function say(?string $text): string
    {
        $text = (string) $text;
        if ($text === '' || self::current() === 'en') {
            return $text;
        }
        $whole = self::t($text);
        if ($whole !== $text) {
            return $whole;
        }
        $names = fn ($list) => implode(', ', array_map(fn ($x) => self::t($x), explode(', ', $list)));
        $rules = [
            ['/^(\S+) decided — (.+)$/u', fn ($m) => self::t('{id} decided — {decision}', ['id' => $m[1], 'decision' => self::t($m[2])])],
            ['/^VSahha needs information on (\S+)$/u', fn ($m) => self::t('VSahha needs information on {id}', ['id' => $m[1]])],
            ['/^The medical team needs information on (\S+)$/u', fn ($m) => self::t('The medical team needs information on {id}', ['id' => $m[1]])],
            ['/^(\S+) issued$/u', fn ($m) => self::t('{id} issued', ['id' => $m[1]])],
            ['/^(\S+) revised — version (\S+)$/u', fn ($m) => self::t('{id} revised — version {v}', ['id' => $m[1], 'v' => $m[2]])],
            ['/^(\S+) expires on (\S+)$/u', fn ($m) => self::t('{id} expires on {d}', ['id' => $m[1], 'd' => $m[2]])],
            ['/^Payment (\S+) released — (.+)$/u', fn ($m) => self::t('Payment {id} released — {amount}', ['id' => $m[1], 'amount' => $m[2]])],
            ['/^Your network standing is now (.+)$/u', fn ($m) => self::t('Your network standing is now {s}', ['s' => self::t($m[1])])],
            ['/^Contract (\S+) updated — (.+)$/u', fn ($m) => self::t('Contract {id} updated — {s}', ['id' => $m[1], 's' => self::t($m[2])])],
            ['/^Contract (\S+) updated$/u', fn ($m) => self::t('Contract {id} updated', ['id' => $m[1]])],
            ['/^Contract (\S+) ends on (\S+)$/u', fn ($m) => self::t('Contract {id} ends on {d}', ['id' => $m[1], 'd' => $m[2]])],
            ['/^Diagnosis re-coded on (\S+)$/u', fn ($m) => self::t('Diagnosis re-coded on {id}', ['id' => $m[1]])],
            ['/^Appeal (\S+) is under review$/u', fn ($m) => self::t('Appeal {id} is under review', ['id' => $m[1]])],
            ['/^Appeal (\S+): (.+)$/u', fn ($m) => self::t('Appeal {id}: {s}', ['id' => $m[1], 's' => self::t($m[2])])],
            ['/^About (\S+)$/u', fn ($m) => self::t('About {id}', ['id' => $m[1]])],
            ['/^Settles (\S+)\.$/u', fn ($m) => self::t('Settles {id}.', ['id' => $m[1]])],
            ['/^(.+) · (\S+)\. Treat and bill before then, or request an extension\.$/u', fn ($m) => "{$m[1]} · {$m[2]}. ".self::t('Treat and bill before then, or request an extension.')],
            ['/^(.+): (Approved|Declined)$/u', fn ($m) => self::t($m[1]).': '.self::t($m[2])],
            ['/^(\S+): (.+)$/u', fn ($m) => "{$m[1]}: ".self::t($m[2])],
            ['/^The lines add up to (.+), the invoice total says (.+)\.$/u', fn ($m) => self::t('The lines add up to {a}, the invoice total says {b}.', ['a' => $m[1], 'b' => $m[2]])],
            ['/^Not found on this document: (.+)\.$/u', fn ($m) => self::t('Not found on this document: {v}.', ['v' => $names($m[1])])],
            ['/^The date of birth (\d{4}-\d{2}-\d{2}) is in the future\. Check the date\.$/u', fn ($m) => self::t('The date of birth {d} is in the future. Check the date.', ['d' => $m[1]])],
            ['/^The (.+) (\d{4}-\d{2}-\d{2}) is in the future\. Check the date\.$/u', fn ($m) => self::t('The {f} {d} is in the future. Check the date.', ['f' => self::t($m[1]), 'd' => $m[2]])],
            ['/^The (.+) (\d{4}-\d{2}-\d{2}) is before the date of birth (\S+)\. Check both dates\.$/u', fn ($m) => self::t('The {f} {d} is before the date of birth {b}. Check both dates.', ['f' => self::t($m[1]), 'd' => $m[2], 'b' => $m[3]])],
            ['/^The discharge date (\S+) is before the admission date (\S+)\.$/u', fn ($m) => self::t('The discharge date {a} is before the admission date {b}.', ['a' => $m[1], 'b' => $m[2]])],
            ['/^The total reads (.+)\. Check the amount\.$/u', fn ($m) => self::t('The total reads {v}. Check the amount.', ['v' => $m[1]])],
            ['/^(.+): (.+) is far above the range (.+)\. Check the decimal point\.$/u', fn ($m) => self::t('{n}: {v} is far above the range {r}. Check the decimal point.', ['n' => $m[1], 'v' => $m[2], 'r' => $m[3]])],
            ['/^(.+): (.+) is far below the range (.+)\. Check the decimal point\.$/u', fn ($m) => self::t('{n}: {v} is far below the range {r}. Check the decimal point.', ['n' => $m[1], 'v' => $m[2], 'r' => $m[3]])],
            ['/^(.+): marked (High|Low) but (.+) is inside the range (.+)\. Check the result\.$/u', fn ($m) => self::t('{n}: marked {f} but {v} is inside the range {r}. Check the result.', ['n' => $m[1], 'f' => self::t($m[2]), 'v' => $m[3], 'r' => $m[4]])],
            ['/^This result line could not be read: “(.+)”\. Add it by hand if it is needed\.$/u', fn ($m) => self::t('This result line could not be read: “{v}”. Add it by hand if it is needed.', ['v' => $m[1]])],
            ['/^Invoice (.+) has already been claimed \((.+)\)\.$/u', fn ($m) => self::t('Invoice {id} has already been claimed ({c}).', ['id' => $m[1], 'c' => $m[2]])],
            ['/^The tax number on the invoice \((.+)\) is not yours \((.+)\)\.$/u', fn ($m) => self::t('The tax number on the invoice ({a}) is not yours ({b}).', ['a' => $m[1], 'b' => $m[2]])],
            ['/^Not covered on (\S+): (.+)$/u', fn ($m) => self::t('Not covered on {d}: {s}', ['d' => $m[1], 's' => self::t($m[2])])],
            ['/^This exact file was already uploaded \((.+), (\S+)\)\.$/u', fn ($m) => self::t('This exact file was already uploaded ({n}, {d}).', ['n' => $m[1], 'd' => $m[2]])],
            ['/^This image looks like one already uploaded \((.+)\)\.$/u', fn ($m) => self::t('This image looks like one already uploaded ({n}).', ['n' => $m[1]])],
            ['/^This PDF was modified after it was created \(with (.+)\)\. Check that the amounts are the original ones\.$/u', fn ($m) => self::t('This PDF was modified after it was created (with {p}). Check that the amounts are the original ones.', ['p' => $m[1]])],
        ];
        foreach ($rules as [$re, $make]) {
            if (preg_match($re, $text, $m)) {
                return $make($m);
            }
        }

        return $text;
    }
}
