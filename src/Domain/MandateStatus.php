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

    public function apiName(): string
    {
        return $this->value;
    }
}
