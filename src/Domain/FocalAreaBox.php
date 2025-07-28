<?php

namespace MatchBot\Domain;

use Assert\Assertion;

/**
 * Defines the position of focal area within an image, which should be preserved and if necessary enlarged
 * when the image is presented on screen, despite the image needing to be cropped and have other content on top.
 *
 * All numbers are given as percentage of image dimensions, measured as displacement right and down from the top left
 * corner. We use four numbers to define two opposite corners of the box.
 */
readonly class FocalAreaBox
{
    /**
     * For now default position is always used - it's possible that the choosable focal position per meta-campaign
     * was more than we needed. If that's confirmed we can get rid of this class and just hard-coded the position
     * in the front end.
     */
    public function __construct(
        public int $topLeftXpos = 70,
        public int $topLeftYpos = 47,
        public int $bottomRightXpos = 70,
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
