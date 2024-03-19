<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use MatchBot\Application\Messenger\StripePayout;
use Psr\Log\LoggerInterface;
use Stripe\Event;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

/**
 * Reconcile donation statuses up to late Feb 2024 – one off to deal with previous payout edge cases.
 */
class ReconcilePastPayouts extends LockingCommand
{
    public const PAYOUT_INFO_CSV = <<<EOT
"acct_1N8Btr4ClEecoLgJ","po_1Oh1kA4ClEecoLgJdYWFWu6D"
"acct_1JV0j6QLghQNyeOQ","po_1OZNuqQLghQNyeOQEYnD01LN"
"acct_1JJGmMQQgZuvVSqR","po_1OZNubQQgZuvVSqRmUsL4I0S"
"acct_1MZdOu4FWuDJMKJ8","po_1OWqaa4FWuDJMKJ8jwQoOciZ"
"acct_1NakCPQKeadnrms3","po_1OWqa8QKeadnrms3g9yLCsSb"
"acct_1NQsR7QMuXa6mZ2s","po_1OWqa0QMuXa6mZ2sxJRegKFj"
"acct_1JIt1UQMnp3yt40u","po_1OWqZTQMnp3yt40uQmnAdkj2"
"acct_1NutsL4CEkBDl7M2","po_1OWqZB4CEkBDl7M28lLslrHB"
"acct_1NIVBtQSSLMOwoeW","po_1OWqZ4QSSLMOwoeWQlpP111u"
"acct_1JJGmMQQgZuvVSqR","po_1OPEd7QQgZuvVSqRZo1NXtTi"
"acct_1NJzl14JefO3N9rH","po_1OPEc24JefO3N9rHWTBOUdIw"
"acct_1Lx67pQKipERQ6J6","po_1OPEbgQKipERQ6J6GeVhhv2S"
"acct_1JO4Z3QRmLbcIvL2","po_1OPEbdQRmLbcIvL28kJ8rpZM"
"acct_1IuusP3ZvDix6HgX","po_1OPEbN3ZvDix6HgXUijmOyfN"
"acct_1JKj2g4EM6tmvjBM","po_1OPEY04EM6tmvjBMlfuQPeZ7"
"acct_1JV0j6QLghQNyeOQ","po_1OPERpQLghQNyeOQB7pXWSnh"
"acct_1LD5IiQLUwo4ey8k","po_1OPERFQLUwo4ey8kpMJJFL72"
"acct_1JZEc64IYkXStEbE","po_1OPEMx4IYkXStEbE1rxjzUYV"
"acct_1L84rX4K425Utr36","po_1OPEJY4K425Utr368m8Dipr1"
"acct_1L84rX4K425Utr36","po_1OMhIw4K425Utr36kS10f3pH"
"acct_1JIZR6QTpdfQfN6L","po_1OMhIhQTpdfQfN6LTgckyb7S"
"acct_1NJzl14JefO3N9rH","po_1OMhIZ4JefO3N9rHbGIVUEvH"
"acct_1L0OQLQNy2klJJEI","po_1OMhHvQNy2klJJEIL4JIvQly"
"acct_1M0OXi4GMWvzPiop","po_1OMhHg4GMWvzPiopdubCXL9v"
"acct_1JJGmMQQgZuvVSqR","po_1OMhHfQQgZuvVSqRMI4KKAPa"
"acct_1LD5IiQLUwo4ey8k","po_1OMhHYQLUwo4ey8k7kBFDDD8"
"acct_1NQsR7QMuXa6mZ2s","po_1OMhHJQMuXa6mZ2sZKic8NDO"
"acct_1KsTU3QTtUeiAST8","po_1OMhEyQTtUeiAST8htflKap2"
"acct_1JKj2g4EM6tmvjBM","po_1OMhET4EM6tmvjBMssISWOKH"
"acct_1JXjYH4Ctr29UXmU","po_1OMhEK4Ctr29UXmUak7mBMjv"
"acct_1LGk5zQTxjVUP6NH","po_1OMhE8QTxjVUP6NHcPid0bmu"
"acct_1NutsL4CEkBDl7M2","po_1OMhDx4CEkBDl7M2GcZ1umoG"
"acct_1JIt1UQMnp3yt40u","po_1OMhDwQMnp3yt40uDWqySQkR"
"acct_1JV0j6QLghQNyeOQ","po_1OMhDtQLghQNyeOQPR1oIDUB"
"acct_1NIVBtQSSLMOwoeW","po_1OMhCnQSSLMOwoeWGc3G06q7"
"acct_1JO4Z3QRmLbcIvL2","po_1OMhCnQRmLbcIvL2viV3ofSn"
"acct_1IuusP3ZvDix6HgX","po_1OMh843ZvDix6HgXmdmbn1rZ"
"acct_1Jefn44CNxfVnYpQ","po_1OMh7p4CNxfVnYpQxPJrIxVI"
"acct_1MZdOu4FWuDJMKJ8","po_1OMh6K4FWuDJMKJ829DyICAW"
"acct_1Lx67pQKipERQ6J6","po_1OMh0bQKipERQ6J6KupwgWKQ"
"acct_1JZEc64IYkXStEbE","po_1OMh0P4IYkXStEbEIySfYPUL"
"acct_1Nsn3z4KdE857qpl","po_1OMgwK4KdE857qplgBF8Zhdc"
"acct_1Jefn44CNxfVnYpQ","po_1OKAG04CNxfVnYpQBBtQtnOA"
"acct_1JO4Z3QRmLbcIvL2","po_1OKAFmQRmLbcIvL258tJZA3P"
"acct_1N8Btr4ClEecoLgJ","po_1OKA2z4ClEecoLgJgp6GNRZx"
"acct_1KsTU3QTtUeiAST8","po_1OK9f7QTtUeiAST88RlfFsYY"
"acct_1Jefn44CNxfVnYpQ","po_1OF4qh4CNxfVnYpQdHNVmf9w"
"acct_1JZEc64IYkXStEbE","po_1OF4pD4IYkXStEbEShDd34Iw"
"acct_1NJzl14JefO3N9rH","po_1OCXhy4JefO3N9rH5w2pY8cL"
"acct_1NOjJ2QTE0klmEBK","po_1O7T5XQTE0klmEBK4BlLIsmZ"
"acct_1J8LHN4JEhilLL4A","po_1O7SwT4JEhilLL4AOG7tnLNa"
"acct_1J8LHN4JEhilLL4A","po_1O4vYD4JEhilLL4Ay4W8pT12"
"acct_1NJzl14JefO3N9rH","po_1O4vWG4JefO3N9rHLpQZWsdl"
"acct_1KVcLPQNJIqmZcBf","po_1O2OFfQNJIqmZcBf2pe3ibx7"
"acct_1NOjJ2QTE0klmEBK","po_1NumJfQTE0klmEBKbD4Vi36u"
"acct_1KVcLPQNJIqmZcBf","po_1NsEukQNJIqmZcBfWNZQtBTJ"
"acct_1NOjJ2QTE0klmEBK","po_1NsErDQTE0klmEBKYtQJZiWK"
"acct_1NOjJ2QTE0klmEBK","po_1NphS4QTE0klmEBKuhVMEq1y"
"acct_1KVcLPQNJIqmZcBf","po_1NphRLQNJIqmZcBfOq0FEb4X"
"acct_1KVcLPQNJIqmZcBf","po_1NnADaQNJIqmZcBfizEvrdpN"
"acct_1KVcLPQNJIqmZcBf","po_1Nkcr3QNJIqmZcBfOU1QC4ea"
"acct_1KVcLPQNJIqmZcBf","po_1Ni5S2QNJIqmZcBfXmXrkvFf"
"acct_1KVcLPQNJIqmZcBf","po_1NfYCMQNJIqmZcBf3pTum1l5"
"acct_1KVcLPQNJIqmZcBf","po_1Nd0qQQNJIqmZcBfslByIE8Z"
"acct_1JQuDb4C6qcJMpK8","po_1NaTrP4C6qcJMpK8OATx9n5u"
"acct_1JO4Z3QRmLbcIvL2","po_1NXw7dQRmLbcIvL2cCRdg8r4"
"acct_1KsTU3QTtUeiAST8","po_1NXw7cQTtUeiAST8ezH3V0zw"
"acct_1KVcLPQNJIqmZcBf","po_1NXw7aQNJIqmZcBfrtjRAwRs"
"acct_1JQuDb4C6qcJMpK8","po_1NVTbD4C6qcJMpK8iqBBXV5O"
"acct_1KVcLPQNJIqmZcBf","po_1NSrRkQNJIqmZcBfBLOWLbby"
"acct_1IuusP3ZvDix6HgX","po_1NQKFN3ZvDix6HgXGgxf3zkk"
"acct_1KsndcQQv2EG7rx4","po_1NQKFMQQv2EG7rx4AuqIH8tA"
"acct_1KVcLPQNJIqmZcBf","po_1NQKFMQNJIqmZcBfxjuyPiH6"
"acct_1JO4Z3QRmLbcIvL2","po_1NNmkcQRmLbcIvL26jWqJbOi"
"acct_1IuusP3ZvDix6HgX","po_1NNmkc3ZvDix6HgXapobokw4"
"acct_1KVcLPQNJIqmZcBf","po_1NNmkcQNJIqmZcBfhItLNL3x"
"acct_1KsndcQQv2EG7rx4","po_1NNmkaQQv2EG7rx4HdjFCHZi"
"acct_1J8LHN4JEhilLL4A","po_1NLFXF4JEhilLL4A8bdXBm9P"
"acct_1KLVXPQOR27a8biw","po_1NLFXDQOR27a8biwLgKbUuz5"
"acct_1IuusP3ZvDix6HgX","po_1NLFWL3ZvDix6HgXDCy5HKgf"
"acct_1KsTU3QTtUeiAST8","po_1NLFWIQTtUeiAST8Sk1uoOO4"
"acct_1JO4Z3QRmLbcIvL2","po_1NLFWIQRmLbcIvL2WplQ7EkX"
"acct_1KsndcQQv2EG7rx4","po_1NIi64QQv2EG7rx4SDmGype7"
"acct_1KLVXPQOR27a8biw","po_1NDdnOQOR27a8biwvBUEk9en"
"acct_1L0OQLQNy2klJJEI","po_1NDdnNQNy2klJJEImHTrV8hM"
"acct_1Lx67pQKipERQ6J6","po_1NDdnNQKipERQ6J6M92LIUL3"
"acct_1J8LHN4JEhilLL4A","po_1NDdnM4JEhilLL4ALaTzUy5t"
"acct_1L0OQLQNy2klJJEI","po_1NB65pQNy2klJJEIcLslPJAI"
"acct_1J8LHN4JEhilLL4A","po_1NB65p4JEhilLL4A8xLkOElh"
"acct_1KLVXPQOR27a8biw","po_1NB65pQOR27a8biwvWrpECCR"
"acct_1Lx67pQKipERQ6J6","po_1NB65pQKipERQ6J6tfo7uWtK"
"acct_1LCipMQQoEOlaqyI","po_1N61V6QQoEOlaqyIykSeztzK"
"acct_1LCipMQQoEOlaqyI","po_1N3U8QQQoEOlaqyIGqOqJyN2"
"acct_1Lx67pQKipERQ6J6","po_1Me6o7QKipERQ6J6KQLj3MGx"
"acct_1JXjYH4Ctr29UXmU","po_1MZ24q4Ctr29UXmUh4DU4TjS"
"acct_1LD5IiQLUwo4ey8k","po_1MWUlQQLUwo4ey8kfKwYKVUj"
"acct_1M0OXi4GMWvzPiop","po_1MHGrQ4GMWvzPiopjdp2OcOP"
"acct_1J8LHN4JEhilLL4A","po_1MHGrM4JEhilLL4AuAvreTZv"
"acct_1JMrh1QNalg57oLv","po_1MHGm1QNalg57oLvzC4wanj6"
"acct_1L0OQLQNy2klJJEI","po_1MHGm0QNy2klJJEIcFn9TG9V"
"acct_1JV0j6QLghQNyeOQ","po_1MHGm0QLghQNyeOQNFUPlKaN"
"acct_1KsndcQQv2EG7rx4","po_1MHGlyQQv2EG7rx4kK6z0EbJ"
"acct_1LD5IiQLUwo4ey8k","po_1MHGlyQLUwo4ey8kMlu37rUR"
"acct_1IuusP3ZvDix6HgX","po_1MHGlU3ZvDix6HgX8xkeZMC1"
"acct_1KLVXPQOR27a8biw","po_1MEjgnQOR27a8biwmicBg6Vr"
"acct_1KsndcQQv2EG7rx4","po_1MEjgnQQv2EG7rx4z2lFhLRt"
"acct_1JIt1UQMnp3yt40u","po_1MEjglQMnp3yt40unvdgktih"
"acct_1L0OQLQNy2klJJEI","po_1MEjamQNy2klJJEIpmXZVg4C"
"acct_1JZEc64IYkXStEbE","po_1MEjRh4IYkXStEbET0ibrjw5"
"acct_1J8LHN4JEhilLL4A","po_1MEjRg4JEhilLL4AA7YvSHj0"
"acct_1LCipMQQoEOlaqyI","po_1MEjRZQQoEOlaqyIrkcVIW1t"
"acct_1JV0j6QLghQNyeOQ","po_1MEjR8QLghQNyeOQihxCX71g"
"acct_1KVcLPQNJIqmZcBf","po_1MEjR8QNJIqmZcBfhxSwlZne"
"acct_1LGk5zQTxjVUP6NH","po_1MEjR8QTxjVUP6NHAuMfLs6R"
"acct_1JMrh1QNalg57oLv","po_1MEjR5QNalg57oLvDfhAJQBz"
"acct_1IuusP3ZvDix6HgX","po_1MEjQv3ZvDix6HgXmnWjqbQm"
"acct_1M0OXi4GMWvzPiop","po_1MEjOf4GMWvzPiopCCj9spKz"
"acct_1JXjYH4Ctr29UXmU","po_1MEjOc4Ctr29UXmULgdGymEA"
"acct_1KVcLPQNJIqmZcBf","po_1MCC8PQNJIqmZcBfRcaY1UCK"
"acct_1KVcLPQNJIqmZcBf","po_1M9etnQNJIqmZcBfmBeUeYXE"
"acct_1KVcLPQNJIqmZcBf","po_1M77PQQNJIqmZcBfkHfjuV3X"
"acct_1KVcLPQNJIqmZcBf","po_1M22mxQNJIqmZcBf3JHB6E0T"
"acct_1KVcLPQNJIqmZcBf","po_1LzVVEQNJIqmZcBfammxWD1R"
"acct_1KVcLPQNJIqmZcBf","po_1Lwy4mQNJIqmZcBfPfztLkLD"
"acct_1KVcLPQNJIqmZcBf","po_1LuQpaQNJIqmZcBfJ3bU7T00"
"acct_1KVcLPQNJIqmZcBf","po_1LrtTeQNJIqmZcBfh20BSUAw"
"acct_1KVcLPQNJIqmZcBf","po_1LpM5GQNJIqmZcBfBWtFhgST"
"acct_1KVcLPQNJIqmZcBf","po_1LmodfQNJIqmZcBfpqvwJdO4"
"acct_1KVcLPQNJIqmZcBf","po_1LSW1HQNJIqmZcBfWFci4zZJ"
"acct_1KVcLPQNJIqmZcBf","po_1LNRPZQNJIqmZcBfDXpEFfX1"
"acct_1IuusP3ZvDix6HgX","po_1LKu4v3ZvDix6HgXsVUHZ6pn"
"acct_1KsndcQQv2EG7rx4","po_1LKtyYQQv2EG7rx43Dj73Out"
"acct_1JO4Z3QRmLbcIvL2","po_1LKtyYQRmLbcIvL28T1lYqmq"
"acct_1KVcLPQNJIqmZcBf","po_1LKtyYQNJIqmZcBfJeFEOooG"
"acct_1IuusP3ZvDix6HgX","po_1LIMez3ZvDix6HgXGzsYILmz"
"acct_1JO4Z3QRmLbcIvL2","po_1LIMcIQRmLbcIvL2nSrARnv3"
"acct_1KsTU3QTtUeiAST8","po_1LIMcFQTtUeiAST8bs7dsRJo"
"acct_1KsndcQQv2EG7rx4","po_1LIMcEQQv2EG7rx4f65sFU5T"
"acct_1IuusP3ZvDix6HgX","po_1LFpQO3ZvDix6HgXWsSeKM1T"
"acct_1JO4Z3QRmLbcIvL2","po_1LFpM5QRmLbcIvL2OPvoI3QZ"
"acct_1KsndcQQv2EG7rx4","po_1LFpM5QQv2EG7rx4xYIsyiWp"
"acct_1KsTU3QTtUeiAST8","po_1LFpM4QTtUeiAST8zhYJhDu6"
"acct_1JQuDb4C6qcJMpK8","po_1LDHwt4C6qcJMpK8lhwjOEw4"
"acct_1KVcLPQNJIqmZcBf","po_1LAkhBQNJIqmZcBfoD7xiJjO"
"acct_1JQuDb4C6qcJMpK8","po_1LAkgF4C6qcJMpK8NwKdAzQG"
"acct_1KVcLPQNJIqmZcBf","po_1L8DMPQNJIqmZcBfPwbZWISZ"
"acct_1KVcLPQNJIqmZcBf","po_1L0bM2QNJIqmZcBfXo1BpHcG"
"acct_1KVcLPQNJIqmZcBf","po_1Ky433QNJIqmZcBfEleV5AJf"
"acct_1KVcLPQNJIqmZcBf","po_1KvWboQNJIqmZcBfmDWwhqib"
"acct_1J8LHN4JEhilLL4A","po_1Kiq2Q4JEhilLL4AIfphlLPF"
"acct_1KVcLPQNJIqmZcBf","po_1Kipy4QNJIqmZcBflMrEyGTW"
"acct_1KLVXPQOR27a8biw","po_1Kipy3QOR27a8biw9InWxUQl"
"acct_1KLVXPQOR27a8biw","po_1KgIg9QOR27a8biwEsWNtixd"
"acct_1KVcLPQNJIqmZcBf","po_1KgIctQNJIqmZcBfYbDCcZ98"
"acct_1J8LHN4JEhilLL4A","po_1KgIb84JEhilLL4AtPjyTq9n"
"acct_1J3KglQM8t45YFB3","po_1KR4d2QM8t45YFB30OjQdQLL"
"acct_1JJGmMQQgZuvVSqR","po_1K9JHYQQgZuvVSqRBKAOgxJN"
"acct_1JV0j6QLghQNyeOQ","po_1K9JHYQLghQNyeOQuiPE3Smo"
"acct_1JKj2g4EM6tmvjBM","po_1K9JHT4EM6tmvjBMmjVlfExy"
"acct_1Jefn44CNxfVnYpQ","po_1K9JFa4CNxfVnYpQiA2MjoC4"
"acct_1JZEc64IYkXStEbE","po_1K9JDo4IYkXStEbENORTwl51"
"acct_1J8LHN4JEhilLL4A","po_1K9JDo4JEhilLL4AbValBh8u"
"acct_1JV0j6QLghQNyeOQ","po_1K6lzTQLghQNyeOQMds9G2UO"
"acct_1JIZR6QTpdfQfN6L","po_1K6lzSQTpdfQfN6Lc7aSXrzI"
"acct_1JJGmMQQgZuvVSqR","po_1K6lzNQQgZuvVSqRw3CQrVk7"
"acct_1IuusP3ZvDix6HgX","po_1K6lv63ZvDix6HgXF9ciPgtQ"
"acct_1Jefn44CNxfVnYpQ","po_1K6ltP4CNxfVnYpQPZOQ4Srt"
"acct_1JKj2g4EM6tmvjBM","po_1K6ltP4EM6tmvjBM8UM8g4OZ"
"acct_1JZEc64IYkXStEbE","po_1K6lsx4IYkXStEbEelTYRlNN"
"acct_1J8LHN4JEhilLL4A","po_1K6lsw4JEhilLL4AwuewjLl4"
"acct_1JQuDb4C6qcJMpK8","po_1Jp0au4C6qcJMpK8YFiOcWuE"
"acct_1IuusP3ZvDix6HgX","po_1J7rqx3ZvDix6HgXRU0sYiCq"
"acct_1IuusP3ZvDix6HgX","po_1J5KWM3ZvDix6HgXg8G7JU4m"
"acct_1JMrh1QNalg57oLv","po_1OtgYYQNalg57oLvhquTPLw2"
EOT;

    protected static $defaultName = 'matchbot:reconcile-payouts';

    public function __construct(
        private RoutableMessageBus $bus,
        private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Reconcile past donations from payout edge cases');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        // Roughly this~ https://www.php.net/manual/en/function.str-getcsv.php#101888
        $payouts = str_getcsv(self::PAYOUT_INFO_CSV, "\n");
        $payouts = array_map('str_getcsv', $payouts);

        $output->writeln(sprintf('Processing %d payouts...', count($payouts)));

        foreach ($payouts as $payout) {
            \assert(is_string($payout[0]));
            $payoutId = $payout[1];
            $message = (new StripePayout())
                ->setConnectAccountId($payout[0])
                ->setPayoutId($payoutId);

            $stamps = [
                new BusNameStamp(Event::PAYOUT_PAID),
                new TransportMessageIdStamp("payout.paid.$payoutId"),
            ];

            try {
                $this->bus->dispatch(new Envelope($message, $stamps));
            } catch (TransportException $exception) {
                $this->logger->error(sprintf(
                    'Payout processing queue dispatch via CLI error %s.',
                    $exception->getMessage(),
                ));

                return 1;
            }
        }

        $output->writeln('Completed past payout processing');

        return 0;
    }
}
