<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions;

use JetBrains\PhpStorm\Pure;
use JsonSerializable;

class ActionPayload implements JsonSerializable
{
    /**
     * @param array<mixed>|object|null $data
     */
    #[Pure]
    public function __construct(
        private int $statusCode = 200,
        private array | object | null $data = null,
        private ?ActionError $error = null
    ) {
    }

    /**
     * @return int
     */
    #[Pure]
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<array-key,mixed>|null|object
     */
    #[Pure]
    public function getData(): object | array | null
    {
        return $this->data;
    }

    /**
     * @return ActionError|null
     */
    #[Pure]
    public function getError(): ?ActionError
    {
        return $this->error;
    }

    /**
     * @return object | array<mixed> | null
     */
    #[\Override]
    public function jsonSerialize(): object | array | null
    {
        $payload = null;

        if ($this->data !== null) {
            $payload = $this->data;
        } elseif ($this->error !== null) {
            $payload = ['error' => $this->error];
        }

        return $payload;
    }
}
