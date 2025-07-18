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
     *
     * For now all image files are just examples for testing - this is not yet used in production.
     */
    public static function forSlug(MetaCampaignSlug $slug): ?BannerLayout
    {
        return match ([Environment::current(), $slug->slug]) {
            [Environment::Local, 'local-test'] => new BannerLayout(
                backgroundColour: Colour::fromHex('#F6F6F6'),
                textBackgroundColour: Colour::fromHex('#FFE500'),
                textColour: Colour::fromHex('#000000'),
                focalArea: new FocalAreaBox(
                    topLeftXpos: 30,
                    topLeftYpos: 47,
                    bottomRightXpos: 60,
                    bottomRightYpos: 47,
                ),
                imageUri: 'https://picsum.photos/id/88/1700/500',
            ),
            [Environment::Staging, 'women-and-girls-2024'], => new BannerLayout(
                backgroundColour: Colour::fromHex('#F6F6F6'),
                textBackgroundColour: Colour::fromHex('#6E0887'),
                textColour: Colour::fromHex('#FFFFFF'),
                focalArea: new FocalAreaBox(
                    topLeftXpos: (int)( 100 * 1401 / 1995),
                    topLeftYpos: (int)( 100 * 185 / 594),
                    bottomRightXpos: (int)( 100 * 1401 / 1995),
                    bottomRightYpos: (int)( 100 * 185 / 594),
                ),
                imageUri: 'https://d1842m250x5wwk.cloudfront.net/uploads/2025/07/WGMF-Campaign.jpg',
            ),
            [Environment::Staging, 'christmas-challenge-2025'], => new BannerLayout(
                backgroundColour: Colour::fromHex('#000000'),
                textBackgroundColour: Colour::fromHex('#B30510'),
                textColour: Colour::fromHex('#FFFFFF'),
                focalArea: new FocalAreaBox(
                    topLeftXpos: 30,
                    topLeftYpos: 47,
                    bottomRightXpos: 60,
                    bottomRightYpos: 47,
                ),
                imageUri: 'https://d1842m250x5wwk.cloudfront.net/uploads/2025/07/double-santa.jpg',
            ),
            [Environment::Staging, 'k2m25'], => new BannerLayout(
                backgroundColour: Colour::fromHex('#000000'),
                textBackgroundColour: Colour::fromHex('#62CFC9'),
                textColour: Colour::fromHex('#000000'),
                focalArea: new FocalAreaBox(
                    topLeftXpos: 30,
                    topLeftYpos: 47,
                    bottomRightXpos: 60,
                    bottomRightYpos: 47,
                ),
                imageUri: 'https://d1842m250x5wwk.cloudfront.net/uploads/2025/07/K2M.png',
            ),
            [Environment::Staging, 'middle-east-humanitarian-appeal-2024'], => new BannerLayout(
                backgroundColour: Colour::fromHex('#000000'),
                textBackgroundColour: Colour::fromHex('#FFE500'),
                textColour: Colour::fromHex('#000000'),
                focalArea: new FocalAreaBox(
                    topLeftXpos: 30,
                    topLeftYpos: 47,
                    bottomRightXpos: 60,
                    bottomRightYpos: 47,
                ),
                imageUri: 'https://d1842m250x5wwk.cloudfront.net/uploads/2025/07/DEC.jpg',
            ),
            default => null,
        };
    }
}
