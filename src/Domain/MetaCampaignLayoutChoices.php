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
    public static function forSlug(MetaCampaign $metaCampaign): ?BannerLayout
    {
        // It may be that we don't ever need to set a specific different focal area for each campaign,
        // so sharing one object here. If confirmed we can delete this class.
        $focalArea = new FocalAreaBox();

        $campaignBannerUriFromSF = $metaCampaign->getBannerUri();

        return match ($metaCampaign->getSlug()->slug) {
            'local-test' => new BannerLayout(
                backgroundColour: Colour::fromHex('#F6F6F6'),
                textBackgroundColour: Colour::fromHex('#FFE500'),
                textColour: Colour::fromHex('#000000'),
                focalArea: $focalArea,
                imageUri: 'https://picsum.photos/id/88/1700/500',
            ),
            'women-an-girls-2024' => new BannerLayout(
                backgroundColour: Colour::fromHex('#F6F6F6'),
                textBackgroundColour: Colour::fromHex('#6E0887'),
                textColour: Colour::fromHex('#FFFFFF'),
                focalArea: $focalArea,
                imageUri: 'https://d1842m250x5wwk.cloudfront.net/uploads/2025/07/WGMF-Campaign.jpg',
            ),
            'christmas-challenge-2025' => new BannerLayout(
                backgroundColour: Colour::fromHex('#000000'),
                textBackgroundColour: Colour::fromHex('#B30510'),
                textColour: Colour::fromHex('#FFFFFF'),
                focalArea: $focalArea,
                imageUri: 'https://d1842m250x5wwk.cloudfront.net/uploads/2025/07/double-santa.jpg',
            ),
            'k2m25' => new BannerLayout(
                backgroundColour: Colour::fromHex('#000000'),
                textBackgroundColour: Colour::fromHex('#62CFC9'),
                textColour: Colour::fromHex('#000000'),
                focalArea: $focalArea,
                imageUri: 'https://d1842m250x5wwk.cloudfront.net/uploads/2025/07/K2M.png',
            ),
            'middle-eas-humanitarian-appeal-2024' => new BannerLayout(
                backgroundColour: Colour::fromHex('#000000'),
                textBackgroundColour: Colour::fromHex('#FFE500'),
                textColour: Colour::fromHex('#000000'),
                focalArea: $focalArea,
                imageUri: 'https://d1842m250x5wwk.cloudfront.net/uploads/2025/07/DEC.jpg',
            ),
            default => new BannerLayout(
                backgroundColour: \is_null($campaignBannerUriFromSF) ? Colour::fromHex('#2C089B') : Colour::fromHex('#F6F6F6'),
                textBackgroundColour: match ($metaCampaign->getFamily()) {
                    // colours taken from variables.scss at https://github.com/thebiggive/components/blob/c096dcaa499ece7080a363d4157bc2e1cd1c5c92/src/globals/variables.scss#L17
                    CampaignFamily::christmasChallenge => Colour::fromHex('#B30510'),
                CampaignFamily::greenMatchFund => Colour::fromHex('#50B400'),
                CampaignFamily::summerGive => Colour::fromHex('#F07D00'),
                CampaignFamily::womenGirls => Colour::fromHex('#6E0887'),
                CampaignFamily::artsforImpact => Colour::fromHex('#BF387D'),
                CampaignFamily::smallCharity => Colour::fromHex('#EE2C65'),
                CampaignFamily::emergencyMatch => Colour::fromHex('#FFE500'),
                CampaignFamily::mentalHealthFund => Colour::fromHex('#62CFC9'),
                CampaignFamily::multiCurrency => Colour::fromHex('#2C089B'),
                CampaignFamily::imf => Colour::fromHex('#2C089B'),
                CampaignFamily::regularGiving => Colour::fromHex('#2C089B'),
                null => Colour::fromHex('#2C089B'),
                },
                textColour: match ($metaCampaign->getFamily()) {
                    // all white or black, chosen to contrast with colours above.
                    CampaignFamily::christmasChallenge => Colour::fromHex('#FFFFFF'),
                CampaignFamily::greenMatchFund => Colour::fromHex('#FFFFFF'),
                CampaignFamily::summerGive => Colour::fromHex('#000000'),
                CampaignFamily::womenGirls => Colour::fromHex('#FFFFFF'),
                CampaignFamily::artsforImpact => Colour::fromHex('#000000'),
                CampaignFamily::smallCharity => Colour::fromHex('#FFFFFF'),
                CampaignFamily::emergencyMatch => Colour::fromHex('#000000'),
                CampaignFamily::mentalHealthFund => Colour::fromHex('#000000'),
                CampaignFamily::multiCurrency => Colour::fromHex('#FFFFFF'),
                CampaignFamily::imf => Colour::fromHex('#FFFFFF'),
                CampaignFamily::regularGiving => Colour::fromHex('#FFFFFF'),
                null => Colour::fromHex('#FFFFFF'),
                },
                focalArea: $focalArea,
                imageUri: $campaignBannerUriFromSF?->__toString(),
            ),
        };
    }
}
