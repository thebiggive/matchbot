<?php

namespace MatchBot\Domain;

class RegularGivingService
{
    /** @psalm-suppress PossiblyUnusedMethod - used by DI container  */
    public function __construct(private RegularGivingMandateRepository $regularGivingMandateRepository)
    {
    }

    public function allActiveForDonorAsApiModel(PersonId $donor): array
    {
        $mandatesWithCharities = $this->regularGivingMandateRepository->allActiveForDonorWithCharities($donor);

        return array_map(/**
         * @param array{0: RegularGivingMandate, 1: Charity} $tuple
         * @return array
         */            static fn(array $tuple) => $tuple[0]->toFrontendApiModel($tuple[1]),
            $mandatesWithCharities
        );
    }
}
