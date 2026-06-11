<?php

declare(strict_types=1);

namespace Iae\Central\Services;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

final class RabbitMqService
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $user,
        private readonly string $pass,
        private readonly string $exchange,
        private readonly string $boardQueue,
    ) {
    }

    /** @return array{ok: bool, error: ?string} */
    public function ping(): array
    {
        try {
            $connection = new AMQPStreamConnection($this->host, $this->port, $this->user, $this->pass);
            $connection->close();

            return ['ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function publish(string $routingKey, array $payload): void
    {
        $connection = new AMQPStreamConnection($this->host, $this->port, $this->user, $this->pass);
        $channel = $connection->channel();

        try {
            $this->ensureBoardInfrastructure($channel);

            $body = json_encode($payload, JSON_THROW_ON_ERROR);
            $message = new AMQPMessage($body, [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            ]);

            $channel->basic_publish($message, $this->exchange, $routingKey);
        } finally {
            $channel->close();
            $connection->close();
        }
    }

    /**
     * Peek messages on the lab bulletin-board queue (re-queued after read).
     *
     * @return array{ok: bool, error: ?string, queue: string, message_count: int, messages: list<array<string, mixed>>}
     */
    public function peekBoard(int $limit = 20): array
    {
        try {
            $connection = new AMQPStreamConnection($this->host, $this->port, $this->user, $this->pass);
            $channel = $connection->channel();

            try {
                $this->ensureBoardInfrastructure($channel);
                [, $messageCount] = $channel->queue_declare($this->boardQueue, true);
                $messages = [];
                $deliveryTags = [];
                $toFetch = $limit <= 0
                    ? (int) $messageCount
                    : min($limit, (int) $messageCount);

                for ($i = 0; $i < $toFetch; ++$i) {
                    $envelope = $channel->basic_get($this->boardQueue, false);
                    if ($envelope === null) {
                        break;
                    }

                    $decoded = json_decode($envelope->getBody(), true);
                    $messages[] = [
                        'routing_key' => $envelope->getRoutingKey(),
                        'payload' => is_array($decoded) ? $decoded : ['body' => $envelope->getBody()],
                    ];
                    $deliveryTags[] = $envelope->getDeliveryTag();
                }

                foreach ($deliveryTags as $tag) {
                    $channel->basic_nack($tag, false, true);
                }

                return [
                    'ok' => true,
                    'error' => null,
                    'queue' => $this->boardQueue,
                    'message_count' => (int) $messageCount,
                    'messages' => array_reverse($messages),
                ];
            } finally {
                $channel->close();
                $connection->close();
            }
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => $e->getMessage(),
                'queue' => $this->boardQueue,
                'message_count' => 0,
                'messages' => [],
            ];
        }
    }

    private function ensureBoardInfrastructure(\PhpAmqpLib\Channel\AMQPChannel $channel): void
    {
        $channel->exchange_declare($this->exchange, 'topic', false, true, false);
        $channel->queue_declare($this->boardQueue, false, true, false, false);
        $channel->queue_bind($this->boardQueue, $this->exchange, '#');
    }
}
