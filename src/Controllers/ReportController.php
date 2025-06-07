
<?php

namespace MyFramework\Controllers;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class ReportController
{
    public function generate(int $userId): array
    {
        $connection = new AMQPStreamConnection('localhost', 5672, 'guest', 'guest');
        $channel = $connection->channel();
        $queueName = 'pdf_generation_queue';
        $channel->queue_declare($queueName, false, true, false, false);

        $jobPayload = json_encode(['user_id' => $userId]);

        $message = new AMQPMessage($jobPayload, ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]);
        $channel->basic_publish($message, '', $queueName);

        echo " [x] Dispatched PDF generation job for User #{$userId}\n";

        $channel->close();
        $connection->close();
        
        return [
            'status' => 'queued',
            'message' => 'Your report generation has started.'
        ];
    }
}
