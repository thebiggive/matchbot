<?php

namespace MatchBot\Domain;

interface RegularGivingMandateRepository
{
    /** @return list<RegularGivingMandate> */
    public function findAll();
}
