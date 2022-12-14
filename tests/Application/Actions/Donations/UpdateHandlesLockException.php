<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Actions\Donations;

use DI\Container;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Actions\ActionPayload;
use MatchBot\Application\Auth\DonationToken;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Tests\Application\DonationTestDataTrait;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Slim\Exception\HttpNotFoundException;
use Slim\Exception\HttpUnauthorizedException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\RateLimitException;
use Stripe\Exception\UnknownApiErrorException;
use Stripe\PaymentIntent;
use Stripe\Service\PaymentIntentService;
use Stripe\StripeClient;

class UpdateHandlesLockException extends TestCase
{
    use DonationTestDataTrait;
    use PublicJWTAuthTrait;
}
