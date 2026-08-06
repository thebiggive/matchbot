<?php

namespace MatchBot\Domain;

use Doctrine\ORM\Query\AST\SelectStatement;
use Doctrine\ORM\Query\SqlWalker;

/**
 * Forces the query to use the Donation.campaign_and_status index.
 *
 * Only for use in queries that select FROM Donation and no other table.
 */
class ForceDonationPerCampaignIndexWalker extends SqlWalker
{
    #[\Override]
    public function walkSelectStatement(SelectStatement $selectStatement): string
    {
        $sql = parent::walkSelectStatement($selectStatement);

        $return = preg_replace(
            '/ FROM (\w+) (\w+)/',
            ' FROM $1 $2 FORCE INDEX (campaign_and_status)',
            $sql,
            1
        );

        // should always be a string unless we introduce a regex syntax error, when it would be null.
        \assert(\is_string($return));

        return $return;
    }
}
