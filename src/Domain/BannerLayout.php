<?php

namespace MatchBot\Domain;

use Laminas\Diactoros\Uri;
use Psr\Http\Message\UriInterface;

/**
 * Details of how the banner should be layed out for a given meta-campaign. Only includes details that
 * we don't already have in SF, as initially will be hard-coded.
 */
readonly class BannerLayout implements \JsonSerializable
{
    public ?UriInterface $imageUri;

    public function __construct(
        /** Only shown during loading and/or if an image fails to load - behind the image.
         * Should therefore be a similar colour to most of the image.
         */
        public Colour $backgroundColour,
        /** Should contrast with the image */
        public Colour $textBackgroundColour,
        /** Should contrast with the text background - typically expected to be black or white */
        public Colour $textColour,
        /** Box that indicates the position of any image subject, to be preserved in crops. */
        public FocalAreaBox $focalArea,
        /** Optional - if given overrides the image chosen in SF for this campaign. May be needed here
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
