<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'CommandLockKeys', options: ['collate' => 'utf8mb4_bin'])]
#[ORM\Entity]
class CommandLockKeys
{
    /**
     * @var string
     */
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 64)]
    public string $key_id;

    /**
     * @var string
     */
    #[ORM\Column(type: 'string', length: 44)]
    public string $key_token;

    /**
     * @var int
     */
    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    public int $key_expiration;
}
