<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * @psalm-suppress UnusedClass - class is not used but DB table `CommandLockKeys` is used, class exists
 * just to reflect the db table.
 */
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

    public function __construct(string $key_id, string $key_token, int $key_expiration)
    {
        $this->key_id = $key_id;
        $this->key_token = $key_token;
        $this->key_expiration = $key_expiration;
    }
}
