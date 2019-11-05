<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\Donations;

use MatchBot\Application\Actions\Action;
use MatchBot\Domain\DomainException\DomainRecordNotFoundException;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;

class Cancel extends Action
{
    /**
     * @return Response
     * @throws DomainRecordNotFoundException
     * @throws HttpBadRequestException
     */
    protected function action(): Response
    {
        if (strlen($this->args['donationId']) !== 18) {
            throw new DomainRecordNotFoundException('Invalid donation ID');
        }

        // TODO look up donation by SF ID and also throw that ^ if not found
    }
}
