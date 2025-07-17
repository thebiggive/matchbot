<?php

namespace MatchBot\Domain;

use MatchBot\Application\Environment;

class MetaCampaignLayoutChoices
{
    /**
     * Returns a hard-coded banner layout appropriate for the given meta-campaign slug. For now
     * hard-coded, but in future all these details may become a property of a meta-campaign entered in SF and stored
     * in the DB.
     *
     * Hex colours should generally match those defined at link below. This is not automatically enforced.
     * https://github.com/thebiggive/components/blob/main/src/globals/variables.scss
     */
    public static function forSlug(MetaCampaignSlug $slug): ?BannerLayout
    {
        return match ([Environment::current(), $slug->slug]) {
            [Environment::Local, 'local-test'] => new BannerLayout(
                backgroundColour: Colour::fromHex('#000000'),
                textBackgroundColour: Colour::fromHex('#FFFFFF'),
                textColour: Colour::fromHex('#000000'),
                focalArea: new FocalAreaBox(
                    topLeftXpos: 70,
                    topLeftYpos: 47,
                    bottomRightXpos: 72,
                    bottomRightYpos: 49,
                ),
            ),
            [Environment::Staging, 'christmas-challenge-2025'], => new BannerLayout(
                backgroundColour: Colour::fromHex('#000000'),
                textBackgroundColour: Colour::fromHex('#B30510'), // CC red
                textColour: Colour::fromHex('#FFFFFF'),
                focalArea: new FocalAreaBox(
                    topLeftXpos: (int)( 100 * 1401 / 1995),
                    topLeftYpos: (int)( 100 * 185 / 594),
                    bottomRightXpos: (int)( 100 * 1401 / 1995),
                    bottomRightYpos: (int)( 100 * 185 / 594),
                ),
                // not the image we want finally for CC 25, just something to test the new layout with:
                imageUri: 'https://images-production.thebiggive.org.uk/0011r00002IMRknAAH/CCampaign%20Banner/1cb18e3c-1075-4295-81aa-4060f37dc741.png',
            ),
            default => null,
        };
    }
}
