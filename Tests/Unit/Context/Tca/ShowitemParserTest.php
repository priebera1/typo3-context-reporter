<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Context\Tca;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\ContextReporter\Context\Tca\ShowitemParser;

final class ShowitemParserTest extends TestCase
{
    private const PALETTES = [
        'headers' => ['showitem' => 'header;Title, --linebreak--, header_layout'],
        'media' => ['showitem' => 'image , --linebreak--,imagecols'],
        'broken' => ['label' => 'No showitem'],
    ];

    #[Test]
    public function fieldsOfTabsAndPalettesAreListedInFormOrder(): void
    {
        $type = ['showitem' => '--div--;General, CType, --palette--;;headers, bodytext;Text, --div--;Images, --palette--;Media;media, assets'];

        self::assertSame(
            ['CType', 'header', 'header_layout', 'bodytext', 'image', 'imagecols', 'assets'],
            (new ShowitemParser())->getFields($type, self::PALETTES, []),
        );
    }

    #[Test]
    public function unknownPalettesAndEmptyEntriesAreSkipped(): void
    {
        $type = ['showitem' => ' , header,, --palette--;;missing, --palette--;;broken, --palette--, header'];

        self::assertSame(['header'], (new ShowitemParser())->getFields($type, self::PALETTES, []));
        self::assertSame([], (new ShowitemParser())->getFields([], self::PALETTES, []));
    }

    #[Test]
    public function subtypesAddAndRemoveFieldsForTheRecordValue(): void
    {
        $type = [
            'showitem' => 'CType, list_type, --palette--;;media, pages',
            'subtype_value_field' => 'list_type',
            'subtypes_addlist' => ['news_pi1' => 'pi_flexform;Settings, --palette--;;headers'],
            'subtypes_excludelist' => ['news_pi1' => 'pages, image'],
        ];
        $parser = new ShowitemParser();

        self::assertSame(
            ['CType', 'list_type', 'imagecols', 'pi_flexform', 'header', 'header_layout'],
            $parser->getFields($type, self::PALETTES, ['list_type' => 'news_pi1']),
        );
        self::assertSame(['CType', 'list_type', 'image', 'imagecols', 'pages'], $parser->getFields($type, self::PALETTES, ['list_type' => 'other']));
        // Like the editing form: without the subtype value in the record, nothing changes
        self::assertSame(['CType', 'list_type', 'image', 'imagecols', 'pages'], $parser->getFields($type, self::PALETTES, []));
    }
}
