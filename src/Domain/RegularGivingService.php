<?php

namespace MatchBot\Domain;

readonly class RegularGivingService
{
    /** @psalm-suppress PossiblyUnusedMethod - used by DI container  */
    public function __construct(
        private RegularGivingMandateRepository $regularGivingMandateRepository,
        private \DateTimeImmutable $now
    ) {
    }

    public function allActiveForDonorAsApiModel(PersonId $donor): array
    {
        $mandatesWithCharities = $this->regularGivingMandateRepository->allActiveForDonorWithCharities($donor);

        $currentUKTime = $this->now->setTimezone(new \DateTimeZone("Europe/London"));

        return array_map(/**
         * @param array{0: RegularGivingMandate, 1: Charity} $tuple
         * @return array
         */            static fn(array $tuple) => $tuple[0]->toFrontendApiModel($tuple[1], $currentUKTime),
            $mandatesWithCharities
        );
    }
}
