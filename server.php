<?php
use Workerman\Worker;
use Workerman\Connection\TcpConnection;
require_once __DIR__ . '/vendor/autoload.php';
@['s'=>$external_port,'c'=>$inner_port] = getopt('s:c:');
// 内部客户端连接验证密钥
define('SECRET_KEY', '<?--------');
// 监听内部客户端的端口
define('INNER_PORT', $inner_port ?? 2346);
// 监听外网的端口
define('EXTERNAL_PORT', $external_port ?? 2347);

$global_uid = 0;
/** @var Worker $external_worker */
$external_worker;
/** @var TcpConnection $client_connection */
$client_connection = null;
// 外网连接
function handle_connection(TcpConnection $s_connection)
{
    global $global_uid;
    // 为这个连接分配一个uid
    $s_connection->uid = ++$global_uid;
    echo "[$s_connection->uid]" . ' 连接已连接' . PHP_EOL;
}

// 外网消息处理
function handle_message(TcpConnection $s_connection, string $data)
{
    global $client_connection;
    // echo $client_connection . PHP_EOL;
    // echo $data;
    if (is_null($client_connection)) {
        $s_connection->close();
    } else {
        // echo $data . PHP_EOL;
        $client_connection->send(pack('I', $s_connection->uid) . $data);
    }
}

// 外网连接关闭
function handle_close(TcpConnection $s_connection)
{
    global $external_worker;
    $uid = $s_connection->uid;
    echo "[$uid]" . ' 连接已关闭' . PHP_EOL;
    // echo json_encode(array_values(array_map(function($connection){
    //     return $connection->uid;
    // }, $external_worker->connections))) . PHP_EOL;
}

// 外网监听
$inner_worker = new Worker('frame://0.0.0.0:' . INNER_PORT);
$inner_worker->count = 1;
$inner_worker->onBufferFull = function(TcpConnection $connection){
    global $external_worker;
    /** @var TcpConnection $value */
    foreach($external_worker->connections as $value) {
        $value->pauseRecv();
    }
    echo 'Full' . PHP_EOL;
};
$inner_worker->onBufferDrain = function(TcpConnection $connection){
    global $external_worker;
    /** @var TcpConnection $value */
    foreach($external_worker->connections as $value) {
        $value->resumeRecv();
    }
    echo 'Drain' . PHP_EOL;
};
$inner_worker->onMessage = function(TcpConnection $inner_connection,string $data) {
    global $external_worker;
    if (empty($data)) { // 心跳数据不用处理
        return;
    }
    $uid = unpack('I', $data)[1];
    $data = substr($data, 4);
    if ($uid === 0 && $data === SECRET_KEY) { // 校验是否是客户端
        global $client_connection;
        if($client_connection) {
            $client_connection->close();
        }
        $client_connection = $inner_connection;
        echo $client_connection->getRemoteAddress() . ' 客户端已连接' . PHP_EOL;
        return;
    }
    /** @var TcpConnection $connection */
    foreach ($external_worker->connections as $connection) {
        if($connection->uid === $uid){
            if (empty($data)) {
                echo "[$uid] 连接被动关闭" . PHP_EOL;
                $connection->close();
            } else {
                $connection->send($data);
            }
            break;
        }
    }
    // $tcpConnection->send(substr($data, 4));
};
$inner_worker->onClose = function(TcpConnection $inner_connection) use (&$client_connection){
    global $external_worker;
    if ($client_connection) {
        echo $client_connection->getRemoteAddress() . ' 客户端已关闭' . PHP_EOL;
    }
    $client_connection = null;
    foreach($external_worker->connections as $connection){
        $connection->close();
    }
};
$inner_worker->onWorkerStart = function(){
    global $external_worker;
    echo '内部客户端监听端口['.INNER_PORT.']开放' . PHP_EOL;
    // 对接外部客户端
    $external_worker = new Worker("tcp://0.0.0.0:" . EXTERNAL_PORT);
    $external_worker->count = 1;
    $external_worker->onConnect = 'handle_connection';
    $external_worker->onMessage = 'handle_message';
    $external_worker->onClose = 'handle_close';
    echo '外网监听端口['.EXTERNAL_PORT.']开放' . PHP_EOL;
    $external_worker->listen();
};
Worker::runAll();
