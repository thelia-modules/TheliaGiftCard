<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Tests;

use Random\Engine\Mt19937;
use Random\Randomizer;
use TheliaGiftCard\Exception\GiftCardCodeGenerationException;
use TheliaGiftCard\Service\GiftCardCodeGenerator;
use TheliaGiftCard\TheliaGiftCard;

final class GiftCardCodeGeneratorTest extends GiftCardTestCase
{
    public function testACodeIsEightCharactersOfTheAlphabet(): void
    {
        $code = (new GiftCardCodeGenerator())->generate();

        self::assertMatchesRegularExpression('/^[A-Z0-9]{8}$/', $code);
    }

    public function testTheLegacyStaticEntryPointDrawsFromTheGenerator(): void
    {
        self::assertMatchesRegularExpression('/^[A-Z0-9]{8}$/', TheliaGiftCard::GENERATE_CODE());
    }

    public function testADrawFallingOnATakenCodeIsDrawnAgain(): void
    {
        $reference = new Randomizer(new Mt19937(20260930));
        $firstDraw = $reference->getBytesFromString(GiftCardCodeGenerator::ALPHABET, GiftCardCodeGenerator::CODE_LENGTH);
        $secondDraw = $reference->getBytesFromString(GiftCardCodeGenerator::ALPHABET, GiftCardCodeGenerator::CODE_LENGTH);
        $this->giftCard(['code' => $firstDraw]);

        $code = (new GiftCardCodeGenerator(new Randomizer(new Mt19937(20260930))))->generate();

        self::assertSame($secondDraw, $code);
    }

    public function testTheGeneratorGivesUpRatherThanHandingOutATakenCode(): void
    {
        $alwaysTheSame = new Randomizer(new FixedEngine());
        $this->giftCard(['code' => $alwaysTheSame->getBytesFromString(GiftCardCodeGenerator::ALPHABET, GiftCardCodeGenerator::CODE_LENGTH)]);

        $this->expectException(GiftCardCodeGenerationException::class);

        (new GiftCardCodeGenerator(new Randomizer(new FixedEngine())))->generate();
    }
}
