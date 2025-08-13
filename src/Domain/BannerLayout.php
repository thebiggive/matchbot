<?php

namespace MatchBot\Domain;

use Laminas\Diactoros\Uri;
use OpenApi\Attributes as OA;
use Psr\Http\Message\UriInterface;

/**
 * Details of how the banner should be layed out for a given meta-campaign. Only includes details that
 * we don't already have in SF, as initially will be hard-coded.
 */
#[OA\Schema(description: "Banner layout configuration for campaign display")]
readonly class BannerLayout implements \JsonSerializable
{
    #[OA\Property(
        property: "imageUri",
        description: "URI for the banner image",
        type: "string",
        format: "uri",
        example: "https://example.com/banner.jpg"
    )]
    public ?UriInterface $imageUri;

    public function __construct(
        #[OA\Property(
            property: "backgroundColour",
            description: "Background color shown during loading or if image fails to load. Should be similar to the image color.",
            ref: "#/components/schemas/Colour"
        )]
        /**
         * Only shown during loading and/or if an image fails to load - behind the image.
         * Should therefore be a similar colour to most of the image.
         */
        public Colour $backgroundColour,
        #[OA\Property(
            property: "textBackgroundColour",
            description: "Color for the text background, should contrast with the image",
            ref: "#/components/schemas/Colour"
        )]
        /**
         * Should contrast with the image
         */
        public Colour $textBackgroundColour,
        #[OA\Property(
            property: "textColour",
            description: "Color for the text, should contrast with the text background (typically black or white)",
            ref: "#/components/schemas/Colour"
        )]
        /**
         * Should contrast with the text background - typically expected to be black or white
         */
        public Colour $textColour,
        #[OA\Property(
            property: "focalArea",
            description: "Box that indicates the position of any image subject, to be preserved in crops",
            ref: "#/components/schemas/FocalAreaBox"
        )]
        /**
         * Box that indicates the position of any image subject, to be preserved in crops.
         */
        public FocalAreaBox $focalArea,
        /**
         * Optional - if given overrides the image chosen in SF for this campaign. May be needed here
         * to allow changing the image and the text colour at the same moment to make sure we always maintain
         * readability.
         */
        ?string $imageUri = null,
    ) {
        $this->imageUri = $imageUri === null ? null : new Uri($imageUri);
    }

    /**
     * @return array<string, array<string, int>|string>
     */
    #[\Override] public function jsonSerialize(): array
    {
        return [
            'backgroundColour' => $this->backgroundColour->toHex(),
            'textBackgroundColour' => $this->textBackgroundColour->toHex(),
            'textColour' => $this->textColour->toHex(),
            'focalArea' => [
                'topLeftXpos' => $this->focalArea->topLeftXpos,
                'topLeftYpos' => $this->focalArea->topLeftYpos,
                'bottomRightXpos' => $this->focalArea->bottomRightXpos,
                'bottomRightYpos' => $this->focalArea->bottomRightYpos,
            ],
        ];
    }
}
