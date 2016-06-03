<?php
/***************************************************************************
 *
 * Copyright (c) 2015 Baidu.com, Inc. All Rights Reserved
 *
 **************************************************************************/

/**
 *@file proxy.php
 *
 *@brief   基于swoole扩展实现http-proxy
 *
 *@author liuzhantao@baidu.com
 *@date   2015-04-21
 *@update 2015-04-21
 *
 */

class Server
{
    private $serv;
    private $data = array('fd'=>'data');//打印数据，key为连接fd，value为收到的数据
    private $forwardMap_ = array('www.aaa.com:234'=>'wwww.bbb.com');//把发往key的请求转发到value
    public function __construct() 
    {
        $this->serv = new swoole_server("0.0.0.0", 9501);
        $this->serv->set(array(
            //'worker_num' => 8,
            'worker_num' => 1,
            'daemonize' => false,
            'max_request' => 10000,
            'dispatch_mode' => 2,
            'debug_mode'=> 1,
            'task_worker_num' => 8,
            'open_http_protocol' => true,//保证post时onReceive能一次收到所有数据
        ));
        $this->serv->on('Start', array($this, 'onStart'));
        $this->serv->on('Connect', array($this, 'onConnect'));
        $this->serv->on('Receive', array($this, 'onReceive'));
        $this->serv->on('Close', array($this, 'onClose'));
        // bind callback
        $this->serv->on('Task', array($this, 'onTask'));
        $this->serv->on('Finish', array($this, 'onFinish'));
        $this->serv->start();

    }
    public function onStart( $serv ) 
    {   
        //服务器启动时执行
        echo "Start serv",PHP_EOL;
    }
    public function onConnect( $serv, $fd, $from_id ) 
    {
        //客户端连接时执行
        //echo "Client {$fd} connect",PHP_EOL;
    }
    public function onReceive( swoole_server $serv, $fd, $from_id, $data ) 
    {
        $request = array();//请求信息
        $headers = explode("\r\n",$data);           
        //第一行，获取host信息
        $tmp = $headers[0];
        
        $tmp = explode(' ',$tmp);

        $method = $tmp[0];
        $url = $tmp[1]; 
        //$httpv = $tmp[2];
        $httpv = 'HTTP/1.0';//1.1会Transfer-Encoding: chunked

        $request['url'] = $url; 
        if (strpos($data,'POST') === 0)
        {
            $request['post'] = end($headers);
        }

        $tmp = explode('/',$url,4);
        $host = $tmp[2];
        $req = '/'.$tmp[3];
        
        //是否需要转发
        if (isset($this->forwardMap_[$host]))
        {
            $host = $this->forwardMap_[$host];
            $request['forward_to'] = $host;
        }

        $port = 80;
        $tmp = explode(':',$host);
        $host = $tmp[0];
        if (isset($tmp[1]))
        {
            $port = intval($tmp[1]);
        }
        $port = ($port > 0) ? $port : 80;

        $headers[0] = $method.' '.$req.' '.$httpv;
        //php环境可能没有安装zlib/deflate扩展，所以Accept-Encoding设为默认
        foreach($headers as $k=>$v)
        {
            if (stripos($v,'Accept-Encoding') !== false)
            {
                $headers[$k] = 'Accept-Encoding: ';
                break;
            }
        
        }
        $strHeaders = implode("\r\n",$headers);
        swoole_async_dns_lookup($host, function($host, $ip) use ($host,$port,$strHeaders,$fd,$request)
        {   
            //var_dump($host);
            //var_dump($ip);
            if(preg_match("/[\d]{2,3}\.[\d]{1,3}\.[\d]{1,3}\.[\d]{1,3}/", $host))
            {
                $ip = $host;
            }
            if (!empty($ip))
            {   
                $client = new swoole_client(SWOOLE_SOCK_TCP,SWOOLE_SOCK_ASYNC);
                $client->on("connect", function(swoole_client $cli) use ($strHeaders,$fd) {
                    $this->data[$fd] = '';
                    $cli->send($strHeaders);
                });
                $client->on("receive", function(swoole_client $cli, $data) use ($fd){
                    $this->data[$fd] .= $data;//一次请求，可能需要多次接收数据才能收完
                    $this->serv->send($fd,$data);
                });
                $client->on("error", function(swoole_client $cli){
                    echo "error",PHP_EOL;
                });
                $client->on("close", function(swoole_client $cli) use ($fd,$request){
                    //print_r($this->data[$fd]);
                    $this->unzip($request,$this->data[$fd]);
                    unset($this->data[$fd]);
                    //echo "client Connection close",PHP_EOL;
                });
                if (!$client->connect(explode(':',$ip)[0], $port, 3))
                {
                    echo "connect failed. Error: {$client->errCode}",PHP_EOL;
                    return;
                }
            }   
        }); 
    }
    private function unzip($request,$data)
    {
        echo '====================================request info====================================',PHP_EOL;
        print_r($request);
        $tmp = explode("\r\n\r\n",$data);          
        if (empty($tmp[1]))
        {
            return ;
        }
        $type = '';
        $encoding = '';

        $temp = explode("\r\n",$tmp[0]);
        foreach($temp as $v)
        {
            $header = explode(':',$v);
            $vv = strtolower(trim($header[1]));
            if (stripos($header[0],'Content-type') === 0)
            {
                
                $type = $vv;
                continue;
            }
            if (stripos($header[0],'Content-Encoding') === 0)
            {
                $encoding = $vv; 
                continue;
            }
        }
        $data = $tmp[1];
        switch($encoding)
        {
            case 'gzip':
            {
                $data = gzdecode($tmp[1]);
                break; 
            }
            case 'deflate':
            {
                $data = gzinflate($tmp[1]);
                break;
            }
            case 'compress':
            {
                break;
            }
            default:
            {
                break;
            }
        }
        //看看是不是json数据
        if (stripos($type,'text') !== false)
        {
            $ret = json_decode($data,true);
            if (is_array($ret))
            {
                $data = $ret;
            }
            echo '====================================return====================================',PHP_EOL;
        }
        print_r($data);
    } 
    public function onClose( $serv, $fd, $from_id ) 
    {
        //echo "Client {$fd} close connection",PHP_EOL;
    }
    public function onTask($serv,$task_id,$from_id, $data) 
    {
        return true;
    }
    public function onFinish($serv,$task_id, $data /* onTask中return的值 */) 
    {
        $serv->finish($data);
    }
}
$server = new Server();


