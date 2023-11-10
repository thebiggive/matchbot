<?php

namespace MatchBot\Application;

use Symfony\Component\Notifier\Bridge\Slack\SlackTransport;
use Symfony\Component\Notifier\Chatter;
use Symfony\Component\Notifier\ChatterInterface;

class SlackChannelChatterFactory
{
    private readonly string $apiToken;

    public function __construct(#[\SensitiveParameter] string $apiToken)
    {
        $this->apiToken = $apiToken;
    }

    public function makeChatter(string $channelName): ChatterInterface
    {
        $transport = new SlackTransport(
            $this->apiToken,
            $channelName,
        );

        return new Chatter($transport);
    }

    /**
     * Copied from https://github.com/Roave/infection-static-analysis-plugin/tree/1.34.x#readme
     * because I want to see how the plugin is working.
     *
     * @template T
     * @param array<T> $values
     * @return list<T>
     */
    function makeAList(array $values): array
    {
        return array_values($values);
    }
}
