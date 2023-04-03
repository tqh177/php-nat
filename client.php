<?php
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Connection\TcpConnection;
use Workerman\Timer;
use Workerman\Worker;
require_once __DIR__ . '/vendor/autoload.php';
// 内部客户端连接服务端的密钥
define('SECRET_KEY', '<?--------');
@['s'=>$external_address,'c'=>$inner_address] = getopt('s:c:');
// nat服务器地址和端口
define('INNER_ADDRESS', $inner_address ?? '127.0.0.1:2346');
// 需要请求的地址和端口
define('EXTERNAL_ADDRESS', $external_address ?? '127.0.0.1:80');

// 设置所有连接的默认应用层发送缓冲区大小
// TcpConnection::$defaultMaxSendBufferSize = 2*1024*1024;

$connections = [];
$worker = new Worker();
$worker->onWorkerStart = function($worker){
    $server_connection = new AsyncTcpConnection("frame://".INNER_ADDRESS);
    $server_connection->index = 0;
    function handle_connection(TcpConnection $s_connection){
        $s_connection->send(pack('I', 0) . SECRET_KEY);
        echo '已连接上远程服务器' . $s_connection->getRemoteAddress() . PHP_EOL;
        echo '转发目的地址' . EXTERNAL_ADDRESS . PHP_EOL;
    }
    function handle_message(TcpConnection $s_connection, string $data)
    {
        global $connections;
        $s_connection->lastMessageTime = time();
        // echo substr($data, 4);
        $uid = unpack('I', $data)[1];
        echo 'uid:' . $uid . PHP_EOL;
        $data = substr($data, 4);
        // echo $data;
        if (array_key_exists($uid, $connections)) {
            /** @var TcpConnection */
            $connection = $connections[$uid];
            if (empty($data)) {
                echo "[$uid]连接被动关闭" . PHP_EOL;
                $connection->close();
                return;
            }
        } elseif (empty($data)) {
            echo "[$uid]不存在的连接被关闭" . PHP_EOL;
            return;
        } else {
            echo "[$uid]open" . PHP_EOL;
            $connections[$uid] = $connection = new AsyncTcpConnection('tcp://'.EXTERNAL_ADDRESS);
            $first = true;
            $connection->onMessage = function(AsyncTcpConnection $connection, string $data)use($s_connection, $uid, &$first){
                $s_connection->send(pack('I', $uid) . $data);
                // if ($first) {
                //     $first = false;
                //     echo substr($data,0,1024) . PHP_EOL;
                // }
            };
            $connection->onBufferFull = function(AsyncTcpConnection $connection) use($s_connection) {
                if ($s_connection->index===0) {
                    $s_connection->pauseRecv();
                }
                echo 'Full:' . $s_connection->index . PHP_EOL;
                $s_connection->index++;
            };
            $connection->onBufferDrain =  function(AsyncTcpConnection $connection) use($s_connection) {
                if ($s_connection->index===0) {
                    $s_connection->resumeRecv();
                } else {
                    $s_connection->index--;
                }
                echo 'Drain:' . $s_connection->index . PHP_EOL;
            };
            $connection->onClose = function(AsyncTcpConnection $connection) use($s_connection, $uid, &$connections) {
                $s_connection->send(pack('I', $uid));
                unset($connections[$uid]);
                echo "[$uid]" . '已发送关闭指令,closed-length:' . count($connections) . PHP_EOL;
            };
            $connection->connect();
        }
        $connection->send($data);
    }
    function handle_close(TcpConnection $s_connection) {
        global $connections;
        echo '失去远程连接' . $s_connection->getRemoteAddress() . PHP_EOL;
        foreach($connections as $connection){
            $connection->close();
        }
        $connections = [];
        $s_connection->reconnect(3);
    }
    $server_connection->onConnect = 'handle_connection';
    $server_connection->onMessage = 'handle_message';
    $server_connection->onClose = 'handle_close';
    $server_connection->onBufferFull = function(TcpConnection $s_connection){
        global $connections;
        // echo 'Full' . PHP_EOL;
        /** @var TcpConnection $connection */
        foreach ($connections as $connection) {
            $connection->pauseRecv();
        }
    };
    $server_connection->onBufferDrain = function(){
        global $connections;
        // echo 'Drain' . PHP_EOL;
        /** @var TcpConnection $connection */
        foreach ($connections as $connection) {
            $connection->resumeRecv();
        }
    };
    $server_connection->connect();
    Timer::add(10, function()use($server_connection){
        $time_now = time();
        if (empty($server_connection->lastMessageTime)) {
            $server_connection->lastMessageTime = $time_now;
        }
        // echo 'heartbeat' . PHP_EOL;
        // 上次通讯时间间隔大于心跳间隔，则认为客户端已经下线，关闭连接
        if ($time_now - $server_connection->lastMessageTime > 1 * 60) {
            $server_connection->lastMessageTime = $time_now;
            // $server_connection->close();
            echo '执行心跳' . PHP_EOL;
            $server_connection->send('');
        }
    });
};
Worker::runAll();
