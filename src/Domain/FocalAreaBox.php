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
    public function __construct(
        public int $topLeftXpos,
        public int $topLeftYpos,
        public int $bottomRightXpos,
        public int $bottomRightYpos,
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
