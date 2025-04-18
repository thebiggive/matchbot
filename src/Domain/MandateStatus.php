<?php

namespace MatchBot\Domain;

enum MandateStatus: string
{
    /**
     * A draft mandate - may not yet be active because the donor hasn't fully confirmed their intention, or because
     * as Big Give we haven't confirmed that e.g. their payment card works.
     */
    case Pending = 'pending';

    /**
     * A completely formed mandate - as Big Give we have permission and intention to take donations as set out
     * in this mandate.
     */
    case Active = 'active';

    /**
     * The donor previously intended to make a regular donation but may have either changed their mind or
     * discontinued the mandate after giving as many donations as they intended.
     */
    case Cancelled = 'cancelled';

    /**
     * Mandate ended because the last collection date for the campaign has passed or is due to pass before the next
     * collection of this mandate
     */
    case CampaignEnded = 'campaign-ended';

    public function apiName(): string
    {
        return $this->value;
    }
}
