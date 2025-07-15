<?php

namespace MatchBot\Domain;

class MetaCampaignLayoutChoices
{
    /**
     * Returns a hard-coded banner layout appropriate for the given meta-campaign slug. For now
     * hard-coded, but in future all these details may become a property of a meta-campaign entered in SF and stored
     * in the DB.
     */
    public static function forSlug(MetaCampaignSlug $slug): ?BannerLayout
    {
        return match ($slug->slug) {
            'local-test' => new BannerLayout(
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
            default => null,
        };
    }
}
