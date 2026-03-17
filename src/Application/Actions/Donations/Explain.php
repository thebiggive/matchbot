<?php

namespace MatchBot\Application\Actions\Donations;

use Assert\Assertion;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\Environment;
use MatchBot\Domain\CampaignService;
use MatchBot\Domain\DomainException\DomainRecordNotFoundException;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationService;
use MatchBot\Domain\FundingWithdrawal;
use MatchBot\Domain\MetaCampaignRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Slim\Exception\HttpNotFoundException;

/**
 * Provides a plain-text explanation of a donation and what happened with it for internal use by Big Give staff.
 */
class Explain extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private DonationRepository $donationRepository,
        private CampaignService $campaignService,
        private MetaCampaignRepository $metaCampaignRepository,
    ) {
        parent::__construct($logger);
    }

    #[\Override]
    protected function action(Request $request, Response $response, array $args): Response
    {
        Assertion::keyExists($args, "donationId");  // shoould always exist as is defined in routes.php
        $donationUUID = $args['donationId'];
        Assertion::string($donationUUID);
        if ($donationUUID === '') {
            throw new DomainRecordNotFoundException('Missing donation ID');
        }

        $donation = $this->donationRepository->findOneByUUID(Uuid::fromString($donationUUID));
        if (! $donation) {
            throw new DomainRecordNotFoundException('Missing donation');
        }


        $text = "Donation Details\n\n";

        $campaign = $donation->getCampaign();
        $text .= "Campaign: {$campaign->getSalesforceId()} '{$campaign->getCampaignName()}' for '{$campaign->getCharity()->getName()}'\n";
        $metaCampaignSlug = $campaign->getMetaCampaignSlug();
        $text .= "Target (generally 2x total match funds): " .
            $this->campaignService->campaignTarget(
                $campaign,
                $metaCampaignSlug ? $this->metaCampaignRepository->getBySlug($metaCampaignSlug) : null
            )->format();
        $text .= "\n--------------------------------------------------------\n\n";

        $text .= "{$donation->getDescription()}\n--------------------------------------------------------\n\n\n";


        $i = 0;

        $donationDetails = $donation->toFrontEndApiModel() + $donation->toSFApiModel();

        ksort($donationDetails);

        // only include the most basic fields and ones about matching, to reduce stuff to read shown here.
        $relevantFields = [
            'amountMatchedByChampionFunds',
            'amountMatchedByPledges',
            'collectedTime',
            'createdTime',
            'donationAmount',
            'matchReservedAmount',
            'matchedAmount',
            'status'
        ];

        $text .=
            $donationDetails
            |> (fn($d) => \array_filter($d, fn(string $key) => \in_array($key, $relevantFields, true), \ARRAY_FILTER_USE_KEY))
            |> (fn($d) => \array_map(function ($key, $value) use (&$i) {
                    $i++;
                    $value = json_encode($value);

                    return sprintf('%-40s %s', $key . ':', $value) . ($i % 5 === 0 ? "\n" : "");
            }, array_keys($d), $d)
            |> (fn($d) => \implode("\n", $d)));


        $text .= "\n\nFunding Withdrawals:\n\n";

        $fundingWithdrawals = $donation->getFundingWithdrawals()->toArray();
        $fundingWithdrawalText = $fundingWithdrawals
            |> (fn(array $withdrawals) => \array_map($this->renderFundingWithdrawal(...), $withdrawals))
            |> (fn(array $d): string => \implode("\n", $d));

        $text .= $fundingWithdrawals === []  ? 'None' : $fundingWithdrawalText;

        $text .= "\n\nPotentially competing donations\n\n";
        $text .= "These are incomplete donations initiated just before this one that may have been competing for donation funds. \n";
        $text .= "Note that the list only includes donations to the same campaign - if the campaign used shared funds ";
        $text .= "then other donations may have affected funds available at the time.\n\n";

        $competingDonations = $this->donationRepository->potentiallyCompetingDonations($donation);
        $competingDonationText = $competingDonations
            |> (fn(array $donations) => \array_map($this->renderOtherDonation(...), $donations))
            |> (fn(array $d): string => \implode("\n", $d));

        $text .= $competingDonations === []  ? 'None' : $competingDonationText;

        $response->getBody()->write($text);

        return $response->withHeader('content-type', 'text/plain');
    }

    private function renderFundingWithdrawal(FundingWithdrawal $fw): string
    {
        $campaignFunding = $fw->getCampaignFunding();
        $fund = $campaignFunding->getFund();

        return "         {$fw->getAmount()} from {$fund->getName()} {$fund->getSalesforceId()}" .
            ($fw->isReleased() ? ", released {$fw->releasedAt->format(\DateTimeImmutable::ATOM)}" : ", not released");
    }

    private function renderOtherDonation(Donation $donation): string
    {
        $ret = "";
        $ret .= "   -  {$donation->getSalesforceId()}: {$donation->getAmount()}"
            . " {$donation->currency()->isoCode()} ({$donation->getDonationStatus()->name}) "
            . "created {$donation->getCreatedDate()->format(\DateTime::ATOM)}\n";

        $fundingWithdrawals = $donation->getFundingWithdrawals();
        if (! $fundingWithdrawals->isEmpty()) {
            $ret .= "      Funding withdrawals:\n";
            foreach ($fundingWithdrawals as $fw) {
                $ret .= $this->renderFundingWithdrawal($fw) . "\n" ;
            }
        }

        return $ret;
    }
}
