
<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use Predis\Client as RedisClient;

echo " [*] Background worker started. Waiting for jobs. To exit press CTRL+C\n";

$connection = new AMQPStreamConnection('0.0.0.0', 5672, 'guest', 'guest');
$channel = $connection->channel();
$redis = new RedisClient();
$queueName = 'pdf_generation_queue';

$channel->queue_declare($queueName, false, true, false, false);

$callback = function ($message) use ($redis) {
    echo " [x] Received job: {$message->body}\n";
    $jobData = json_decode($message->body, true);
    $userId = $jobData['user_id'];

    echo " [>] Generating PDF for User #{$userId}...\n";
    sleep(10); // Simulate heavy work
    $reportUrl = "/downloads/report-{$userId}-" . time() . ".pdf";
    echo " [<] PDF generated: {$reportUrl}\n";

    $notificationChannel = "user_notifications:{$userId}";
    $notificationPayload = json_encode([
        'type' => 'job_complete',
        'event' => 'report_ready',
        'data' => [
            'url' => $reportUrl,
            'message' => 'Your report is now available!'
        ]
    ]);
    $redis->publish($notificationChannel, $notificationPayload);
    echo " [!] Notified Redis channel '{$notificationChannel}'\n";

    $message->delivery_info['channel']->basic_ack($message->delivery_info['delivery_tag']);
    echo " [✔] Done.\n\n";
};

$channel->basic_qos(null, 1, null);
$channel->basic_consume($queueName, '', false, false, false, false, $callback);

while ($channel->is_consuming()) {
    $channel->wait();
}
