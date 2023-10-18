<?php

namespace MatchBot\Application;

use Assert\Assert as BaseAssert;

/**
 * Child class created as recommend at https://github.com/beberlei/assert#your-own-assertion-class. Use this
 * rather than the parent class. If you need to catch the exception catch our own AssertionFailedException so we can
 * distinguish between assertion failures from our own code and assertion failures in any libraries that might also use
 * beberlei/assert
 *
 */
class Assert extends BaseAssert
{
    protected static $lazyAssertionExceptionClass = LazyAssertionException::class;
}