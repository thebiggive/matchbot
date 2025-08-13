<?php

namespace MatchBot\Domain;

use Assert\Assertion;
use OpenApi\Attributes as OA;

/**
 * Defines the position of focal area within an image, which should be preserved and if necessary enlarged
 * when the image is presented on screen, despite the image needing to be cropped and have other content on top.
 *
 * All numbers are given as percentage of image dimensions, measured as displacement right and down from the top left
 * corner. We use four numbers to define two opposite corners of the box.
 */
#[OA\Schema(description: "Defines the position of focal area within an image that should be preserved during cropping")]
readonly class FocalAreaBox
{
    /**
     * For now default position is always used - it's possible that the choosable focal position per meta-campaign
     * was more than we needed. If that's confirmed we can get rid of this class and just hard-coded the position
     * in the front end.
     */
    public function __construct(
        #[OA\Property(
            property: "topLeftXpos",
            description: "X position of the top-left corner as percentage from left (0-100)",
            type: "integer",
            minimum: 0,
            maximum: 100,
            example: 70
        )]
        public int $topLeftXpos = 70,
        #[OA\Property(
            property: "topLeftYpos",
            description: "Y position of the top-left corner as percentage from top (0-100)",
            type: "integer",
            minimum: 0,
            maximum: 100,
            example: 47
        )]
        public int $topLeftYpos = 47,
        #[OA\Property(
            property: "bottomRightXpos",
            description: "X position of the bottom-right corner as percentage from left (0-100)",
            type: "integer",
            minimum: 0,
            maximum: 100,
            example: 70
        )]
        public int $bottomRightXpos = 70,
        #[OA\Property(
            property: "bottomRightYpos",
            description: "Y position of the bottom-right corner as percentage from top (0-100)",
            type: "integer",
            minimum: 0,
            maximum: 100,
            example: 47
        )]
        public int $bottomRightYpos = 47,
    ) {
        Assertion::allBetween(
            [$this->topLeftXpos, $this->topLeftYpos, $this->bottomRightXpos, $this->bottomRightYpos],
            0,
            100
        );

        Assertion::greaterOrEqualThan($this->bottomRightXpos, $this->topLeftXpos);
        Assertion::greaterOrEqualThan($this->bottomRightYpos, $this->topLeftYpos);
    }
}
