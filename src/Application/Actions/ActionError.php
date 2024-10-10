<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions;

use JetBrains\PhpStorm\ArrayShape;
use JetBrains\PhpStorm\Pure;
use JsonSerializable;

class ActionError implements JsonSerializable
{
    public const string BAD_REQUEST = 'BAD_REQUEST';
    public const string INSUFFICIENT_PRIVILEGES = 'INSUFFICIENT_PRIVILEGES';
    public const string NOT_ALLOWED = 'NOT_ALLOWED';
    public const string NOT_IMPLEMENTED = 'NOT_IMPLEMENTED';
    public const string RESOURCE_NOT_FOUND = 'RESOURCE_NOT_FOUND';
    public const string SERVER_ERROR = 'SERVER_ERROR';
    public const string UNAUTHENTICATED = 'UNAUTHENTICATED';
    public const string VALIDATION_ERROR = 'VALIDATION_ERROR';
    public const string VERIFICATION_ERROR = 'VERIFICATION_ERROR';

    #[Pure]
    public function __construct(
        private string $type,
        private ?string $description
    ) {
    }

    /**
     * @return string
     */
    #[Pure]
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @param string $type
     * @return self
     */
    public function setType(string $type): self
    {
        $this->type = $type;
        return $this;
    }

    /**
     * @return null|string
     */
    #[Pure]
    public function getDescription(): string|null
    {
        return $this->description;
    }

    /**
     * @param string|null $description
     * @return self
     */
    public function setDescription(?string $description = null): self
    {
        $this->description = $description;
        return $this;
    }

    /**
     * @return array
     */
    #[ArrayShape([
        'type' => 'string',
        'description' => 'string'
    ])]
    public function jsonSerialize(): array
    {
        return [
            'type' => $this->type,
            'description' => $this->description,
        ];
    }
}
