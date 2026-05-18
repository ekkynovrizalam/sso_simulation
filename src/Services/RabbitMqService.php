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
    ) {
    }

    public function publish(string $routingKey, array $payload): void
    {
        $connection = new AMQPStreamConnection($this->host, $this->port, $this->user, $this->pass);
        $channel = $connection->channel();

        try {
            $channel->exchange_declare(
                $this->exchange,
                'topic',
                false,
                true,
                false
            );

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
}
