<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The front-office catalogues of the Flexy theme say every text the French one says: a key missing
 * from one reads in English on the shop of that language (the payment step of a German shop did).
 */
final class FrontCatalogueTest extends TestCase
{
    #[DataProvider('catalogues')]
    public function testACatalogueTranslatesEveryTextOfTheFrenchOne(string $locale): void
    {
        $french = self::catalogue('fr_FR');
        $translated = self::catalogue($locale);

        self::assertSame([], array_keys(array_diff_key($french, $translated)), 'Missing from '.$locale.'.php');
    }

    #[DataProvider('catalogues')]
    public function testATranslationKeepsThePlaceholdersOfItsText(string $locale): void
    {
        $lost = [];

        foreach (self::catalogue($locale) as $text => $translation) {
            preg_match_all('/%\w+%/', $text, $placeholders);
            preg_match_all('/%\w+%/', $translation, $kept);

            if ([] !== array_diff($placeholders[0], $kept[0])) {
                $lost[] = $text;
            }
        }

        self::assertSame([], $lost);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function catalogues(): iterable
    {
        yield 'de_DE' => ['de_DE'];
        yield 'en_US' => ['en_US'];
    }

    /**
     * @return array<string, string>
     */
    private static function catalogue(string $locale): array
    {
        return require \dirname(__DIR__).'/I18n/frontOffice/flexy/'.$locale.'.php';
    }
}
