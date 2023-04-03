# PHP端口映射工具
## 简介

PHP端口映射工具，使用php+composer+[workerman/workerman](https://www.workerman.net/doc/workerman/README.html)开发，实现了心跳检测、自动重连、多连接访问等功能。<br/>
服务端对外默认端口：2347 连接客户端默认端口：2346<br/>


## 应用场景

接收第三方事件推送时方便调试，如公众号推送事件、钉钉机器人……

## 原理

浏览器0<---tcp--->

浏览器1<---tcp---> 服务端(server.php) <--tcp-->客户端(client.php)<----->资源

浏览器2<---tcp--->

## 安装

```shell
# composer安装php库workerman/workerman
composer install
```

## 启动
### 服务端
```shell
# 在外网能访问的服务端运行server.php，启动后打开两个端口：一个监听外网请求，一个监听客户端连接
php server.php start
# daemon方式运行
php server.php start -d
```

### 客户端
```shell
# 能访问外网的主机运行client.php，启动后将连接服务端并映射到指定ip端口上
php client.php start
```

## 更多
其他命令详见[workerman/workerman](https://www.workerman.net/doc/workerman/install/start-and-stop.html)文档