<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name="CommandLockKeys", options={"collate"="utf8mb4_bin"})
 */
class CommandLockKeys
{
    /**
     * @ORM\Id
     * @ORM\Column(type="string", length=64)
     * @var string
     */
    public $key_id;

    /**
     * @ORM\Column(type="string", length=44)
     * @var string
     */
    public $key_token;

    /**
     * @ORM\Column(type="integer", options={"unsigned"=true})
     * @var int
     */
    public $key_expiration;
}
