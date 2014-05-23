<?php

/**
* BeanStalk - A PHP Client Library for the beanstalkd in-memory workqueue server
* 
* Read more about beanstalkd at http://xph.us/software/beanstalkd/
* This library is compatible with all versions of beanstalkd from 1.0 to 2.0, 
* excluding 2.0 itself.
* 
* NOTE: The library depends on syck (http://whytheluckystiff.net/syck/) for the 
* optional parsing of the YAML output produced by some of beanstalkd's commands, 
* namely the stats* and list* commands. Syck and its associated PHP extension 
* must be installed in order to facilitate the 'auto_unyaml' option in the open() 
* factory method.
* 
* Copyright (c) 2008 Verkkostadi Technologies, Inc
* 
* Permission is hereby granted, free of charge, to any person obtaining a copy
* of this software and associated documentation files (the "Software"), to deal
* in the Software without restriction, including without limitation the rights
* to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
* copies of the Software, and to permit persons to whom the Software is
* furnished to do so, subject to the following conditions:
* 
* The above copyright notice and this permission notice shall be included in
* all copies or substantial portions of the Software.
* 
* THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
* IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
* FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
* AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
* LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
* OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
* THE SOFTWARE.
* 
* @author Tim Gunter <tim@vstadi.com>
* @license http://www.opensource.org/licenses/mit-license.php
*/

/**
* Class: BeanStalk
* 
* Top level control structure for managing a cluster of beanstalks
* 
* @package BeanStalk
*/
class BeanStalk
{
    const DEBUG = false;
    const VERSION = "1.2.2";
    
    private $servers;
    private $server_numerics;
    private $select_mode;
    
    private $connection_timeout;
    private $connection_retries;
    private $auto_unyaml;
    
    private $reserver;
    private $nextserver;
    private $lastserver;
    private $pool_size;
    private $internal;
    
    /**
    * BeanStalk Constructor
    * 
    * @param mixed $in_connection_settings
    * @return BeanStalk
    */
    private function __construct($in_connection_settings)
    {        
        $this->connection_timeout = $in_connection_settings['connection_timeout'];
        $this->connection_retries = $in_connection_settings['connection_retries'];
        $this->auto_unyaml = $in_connection_settings['auto_unyaml'];
        
        $this->log = false;
        if (isset($in_connection_settings['log']) && $in_connection_settings['log'])
            $this->log = $in_connection_settings['log'];
        
        $this->pool_size = 0;
        $this->lastserver = -1;
        $this->internal = 0;
        
        $this->servers = array(); $this->pool_size = 0;
        foreach ($in_connection_settings['servers'] as $server)
            $this->add_server($server);
        
        if (!$this->pool_size)
            throw new BeanQueueNoValidServersException();
        
        $this->select_mode = $in_connection_settings['select'];
        
        $split = explode(' ',$this->select_mode);
        $reserver = '_reserve_'.array_pop($split);
        $nextserver = implode(' ',$split);
        
        if (!method_exists($this,$reserver))
            throw new BeanQueueInvalidSelectorBadReserverException();
            
        $this->reserver = array(&$this,$reserver);
        $this->nextserver = $nextserver;

    }
    
    /**
    * BeanStalk Factory
    * 
    * @param mixed $in_connection_settings
    * @return BeanStalk
    */
    public static function open($in_connection_settings)
    {
        $defaults = array(
            'servers'               => array(),
            'select'                => 'random wait',
            'connection_timeout'    => 0.5,
            'connection_retries'    => 3,
            'auto_unyaml'           => true
        );
        
        $settings = array_merge($defaults, $in_connection_settings);
        
        if (!sizeof($settings['servers']))
            throw new BeanQueueNoServersSuppliedException();
            
        return new BeanStalk($settings);
    }
    
    public function add_server($in_server_str)
    {
        // Don't index invalid servers
        try
        {
            $this->servers[$in_server_str] = new BeanQueue($in_server_str, $this->connection_timeout, $this->connection_retries, $this->auto_unyaml, $this->log);
            $this->server_numerics[$this->pool_size] = $in_server_str;
            $this->pool_size++;
            return true;
        }
        catch (BeanQueueInvalidServerException $e){}
        return false;
    }
    
    public function remove_server($in_server_str)
    {
        if (isset($this->servers[$in_server_str]))
        {
            unset($this->server[$in_server_str]);
            unset($this->server[array_search($in_server_str,$this->server_numerics)]);
            $this->server_numerics = array_values($this->server_numerics);
            $this->pool_size--;
        }
        return false;
    }
    
    /**
    * Get reference to an active BeanQueue
    * 
    * @param mixed $in_server_str
    * @return BeanQueue
    */
    public function &get_server($in_server_str)
    {
        try
        {
            if (isset($this->servers[$in_server_str]) && $this->servers[$in_server_str]->alive('fast'))
                return $this->servers[$in_server_str];
        }
        catch (BeanQueueCouldNotConnectException $e)
        {
            $this->remove_server($in_server_str);
        }
        
        if (!$this->pool_size)
            throw new BeanQueueNoValidServersException;
        
        $f = false;
        return $f;
    }
    
    public function &get_nth($in_server_num)
    {
        if (!$this->pool_size)
            throw new BeanQueueNoValidServersException;
        return $this->server_numerics[$in_server_num];
    }
    
    private function &next_server()
    {
        while (true)
        {
            if (!$this->pool_size)
                throw new BeanQueueNoValidServersException;
                
            switch ($this->nextserver)
            {
                case 'random':
                    $this->lastserver = mt_rand(0,$this->pool_size-1);
                    break;
                    
                case 'sequential':
                    if ($this->lastserver >= $this->pool_size-1)
                        $this->lastserver = 0;
                    else
                        $this->lastserver++;
                    break;
            }
            $server_name = $this->get_nth($this->lastserver);
            $server = $this->get_server($server_name);
            if ($server !== false)
                break;

        }
        return $server;
    }
    
    private function reserver()
    {
        return call_user_func($this->reserver);
    }
    
    /**
    * Wait mode reservation
    * 
    * Pick a server and then wait for a job to become available.
    */
    private function &_reserve_wait()
    {
        return $this->next_server();
    }
    
    /**
    * Peek mode reservation
    * 
    * Iterate over all servers until a job is found on one of them.
    */
    private function &_reserve_peek()
    {
        // Loop through servers until a job is found
        $server = null;
        while (true)
        {
            $server = $this->next_server();
            if ($server)
            {
                // If this server has a job...
                $job = $server->reserve_with_timeout(0);
                if (BeanQueueJob::check($job))
                {
                    $job->release(0,0);
                    break;
                }
            }
            usleep(100000);
        }
        
        if ($server)
            return $server;
        return false;
    }
    
    /**
    * Fire a method on the next server
    * 
    * Use the specified reserver() to grab a server reference, then do this command
    * on it, passing the supplied args along too.
    * 
    * @param mixed $in_command method to execute
    * @param mixed $in_args arguments to pass
    * @return mixed
    */
    private function _do_next_server($in_command, $in_args=array())
    {
        return $this->_do_my_server($this->reserver(), $in_command, $in_args);
    }
    
    private function _do_my_server(&$in_server, $in_command, $in_args=array())
    {
        if (BeanStalk::DEBUG) echo __METHOD__."\n";
        if ($in_server === false)
            return false;
        
        if (!is_callable(array($in_server,$in_command)))
            return false;
        
        if (!is_array($in_args))
            $in_args = array($in_args);
        
        return call_user_func_array(array($in_server, $in_command), $in_args);
    }
    
    private function reset()
    {
        $this->internal = 0;
        return false;
    }
    
    private function &next()
    {
        if (!$this->pool_size)
            throw new BeanQueueNoValidServersException;
            
        $f = false; $t = true;
        while (true)
        {
            if ($this->internal >= $this->pool_size)
                return $f;
            
            $current = $this->internal++;
            $server_name = $this->get_nth($current);
            $server = $this->get_server($server_name);
            if ($server === false)
                continue;
            
            if ($server->alive() === true)
                return $server;
        }
    }
    
    /**
    * REAL COMMANDS
    */
    
    public function __call($in_method, $in_args)
    {
        if (BeanStalk::DEBUG) echo __METHOD__."({$in_method})\n";
        switch ($in_method)
        {
            // Specific server methods
            case 'peek':
            case 'peek_ready':
            case 'kick':
                $servername = array_shift($in_args);
                $server = $this->get_server($servername);
                return $this->_do_my_server($server, $in_method, $in_args);
                break;
            
            // Broadcast to all servers
            case 'watch':
            //case 'ignore':
            case 'use_tube':
                $res = array();
                $this->reset();
                while (($server = @$this->next()) !== false)
                    $res[$server->identify()] = $this->_do_my_server($server, $in_method, $in_args);
                
                return $res;
                break;
                
            // Thanks Kevin :p
            default:
                trigger_error("Fatal error: Call to undefined method ".__CLASS__."::$in_method", E_USER_ERROR);
                break;
        }
    }
    
    public function put()
    {
        if (BeanStalk::DEBUG) echo __METHOD__."\n";
        $server = $this->next_server(); $args = func_get_args();
        return $this->_do_my_server($server, 'put', $args);
    }
    
    /**
    * Reserve a job
    * 
    * Picks a server according to the reserver() method, and then blocks while waiting for a job.
    * 
    * @param $in_wait int seconds to wait for a job to become available
    * 
    * @return BeanQueueJob or false
    */
    public function reserve($in_circulate_pool=true)
    {
        if (BeanStalk::DEBUG) echo __METHOD__."\n";
        
        do
        {
            $reserve = $this->reserver();
            if ($reserve === false)
                return false;
        
            if ($reserve instanceof BeanQueue)
            {
                $job = $reserve->reserve();
                if ($job instanceof BeanQueueJob)
                    return $job;
            }
            
            if ($reserve instanceof BeanQueueJob)
                return $reserve;
                
        } while ($in_circulate_pool);
        return false;
    }
    
    public function reserve_with_timeout($in_wait=0, $in_circulate_pool=true, $in_wait_is_cumulative=false)
    {
        if (BeanStalk::DEBUG) echo __METHOD__."\n";
        $solo_wait = ($in_wait_is_cumulative) ? ceil($in_wait / $this->pool_size) : $in_wait;
        $this->reset();
        while (($server = @$this->next()) !== false)
        {
            $result = $server->reserve_with_timeout($solo_wait);
            if ($result instanceof BeanQueueJob)
                return $result;
        }
        return false;
    }
    
    public function stats(&$out_stats)
    {
        if (BeanStalk::DEBUG) echo __METHOD__."\n";
        $out_stats = array(); $this->reset();
        $results = array();
        while (($server = @$this->next()) !== false)
        {
            $result = $server->stats($srvstats);
            $results[$server->identify()] = $result;
            $out_stats[$server->identify()] = ($result == BeanQueue::OPERATION_OK) ? $srvstats : false;
        }
        return BeanQueue::OPERATION_OK;
    }
    
    public function ignore($in_tube, &$out_reply)
    {
        if (BeanStalk::DEBUG) echo __METHOD__."\n";
        $out_reply = array(); $this->reset();
        $results = array();
        while (($server = @$this->next()) !== false)
        {
            $result = $server->ignore($in_tube, $ign_reply);
            $results[$server->identify()] = $result;
            $out_reply[$server->identify()] = ($result == BeanQueue::OPERATION_OK) ? $ign_reply : false;
        }
        return BeanQueue::OPERATION_OK;
    }
    
    public function stats_job($in_job_id, &$out_stats)
    {
        if (BeanStalk::DEBUG) echo __METHOD__."\n";
        $out_stats = array(); $this->reset();
        $results = array();
        while (($server = @$this->next()) !== false)
        {
            $result = $server->stats_job($in_job_id, $srvstats);
            $results[$server->identify()] = $result;
            $out_stats[$server->identify()] = ($result == BeanQueue::OPERATION_OK) ? $srvstats : false;
        }
        return BeanQueue::OPERATION_OK;
    }
    
    public function stats_tube($in_tube, &$out_stats)
    {
        if (BeanStalk::DEBUG) echo __METHOD__."\n";
        $out_stats = array(); $this->reset();
        $results = array();
        while (($server = @$this->next()) !== false)
        {
            $result = $server->stats_tube($in_tube, $srvstats);
            $results[$server->identify()] = $result;
            $out_stats[$server->identify()] = ($result == BeanQueue::OPERATION_OK) ? $srvstats : false;
        }
        return BeanQueue::OPERATION_OK;
    }
    
    public function list_tubes(&$out_tubes)
    {
        if (BeanStalk::DEBUG) echo __METHOD__."\n";
        $out_tubes = array(); $this->reset();
        $results = array();
        while (($server = @$this->next()) !== false)
        {
            $result = $server->list_tubes($srvtube);
            $results[$server->identify()] = $result;
            $out_tubes[$server->identify()] = ($result == BeanQueue::OPERATION_OK) ? $srvtube : false;
        }
        return BeanQueue::OPERATION_OK;
    }
    
    public function list_tubes_watched(&$out_tubes)
    {
        if (BeanStalk::DEBUG) echo __METHOD__."\n";
        $out_tubes = array(); $this->reset();
        $results = array();
        while (($server = @$this->next()) !== false)
        {
            $result = $server->list_tubes_watched($srvtube);
            $results[$server->identify()] = $result;
            $out_tubes[$server->identify()] = ($result == BeanQueue::OPERATION_OK) ? $srvtube : false;
        }
        return BeanQueue::OPERATION_OK;
    }
    
    /**
    * Delete a job
    * 
    * @param BeanQueueJob $in_job job to delete
    * @return integer operation status
    */
    public static function delete(&$in_job)
    {
        if (BeanStalk::DEBUG) echo __METHOD__."\n";
        return $in_job->delete();
    }
    
    /**
    * Release a job
    * 
    * @param BeanQueueJob $in_job job to release
    * @param integer $in_pri new priority
    * @param integer $in_delay delay before job becomes ready
    * @return integer operation status
    */
    public static function release(&$in_job, $in_pri, $in_delay)
    {
        if (BeanStalk::DEBUG) echo __METHOD__."\n";
        return $in_job->release($in_pri, $in_delay);
    }
    
    /**
    * Bury a job
    * 
    * @param BeanQueueJob $in_job job to bury
    * @param integer $in_pri new priority
    * @return integer operation status
    */
    public static function bury(&$in_job, $in_pri)
    {
        if (BeanStalk::DEBUG) echo __METHOD__."\n";
        return $in_job->bury($in_pri);
    }
    
}

/**
* Class: BeanQueue
* 
* Represents and interfaces with a beanstalkd socket.
* 
* @package BeanStalk
*/
class BeanQueue
{
    
    const MAX_READ_BUF = 16384;
    const MSG_DELIM = "\r\n";
    const READ_ERROR = false;
    
    const OPERATION_OK = 1;
    const MODE_DRAINING = 2;
    const MODE_NORMAL = 4;
    const ERROR_OOM = 8;
    const ERROR_INTERNAL = 16;
    const ERROR_BAD_FORMAT = 32;
    const ERROR_UNKNOWN_COMMAND = 64;
    const ERROR_LAST_TUBE = 128;
    const ERROR_NOT_FOUND = 256;
    const ERROR_DEADLINE_SOON = 512;
    const ERROR_EXPECTED_CRLF = 1024;
    const ERROR_JOB_TOO_BIG = 2048;
    const ERROR_BURIED = 4096;
    const ERROR_BAD_PRIORITY = 8192;
    const ERROR_BAD_DELAY = 16384;
    const ERROR_BAD_TTR = 32768;
    const ERROR_NOT_CONNECTED = 65536;
    const ERROR_TIMED_OUT = 131072;
    
    private $alive;                 // Connected and ready?
    private $ip;                    // Server IP
    private $port;                  // Server Port
    
    private $timeout;               // fsockopen timeout
    private $max_retries;           // Connection retry max
    private $auto_unyaml;           // Automatically unyaml stats and lists?
    private $log;                   // Path to this instance's log file
    
    private $retries;               // Current total connection retries
    private $socket;                // Reference to socket
    private $rbuf;                  // Read buffer
    private $rbuflen;
    private $mode;                  // Server job mode. self::MODE_NORMAL or self::MODE_DRAINING
    
    private $tube;                  // Currently used tube
    
    private $recovery;
    private $preparing;
    
    public function __construct($in_server_str, $in_conn_to, $in_conn_r, $in_auto_unyaml, $in_logfile)
    {
        $server = explode(':',$in_server_str);
        
        if (ip2long($server[0]) === false)
            throw new BeanQueueInvalidServerException();
            
        if ($server[1] < 1 || $server[1] > 65536)
            throw new BeanQueueInvalidServerException();
            
        $this->ip = $server[0];
        $this->port = $server[1];
        $this->retries = 0;
        $this->timeout = $in_conn_to;
        $this->max_retries = $in_conn_r;
        $this->auto_unyaml = $in_auto_unyaml;
        $this->log = $in_logfile;
        $this->tube = 'default';
        $this->recovery = array(
            'use'       => '',
            'ignore'    => array(),
            'watch'     => array()
        );
        
        $this->alive = null;
        $this->rbuf = null;
        $this->rbuflen = 0;
    }
    
    private function check_reply($in_reply, $in_expr_arr, &$writeback)
    {
        if ($in_reply == 'OUT_OF_MEMORY')
            return self::ERROR_OOM;
        if ($in_reply == 'INTERNAL_ERROR')
            return self::ERROR_INTERNAL;
        if ($in_reply == 'UNKNOWN_COMMAND')
            return self::ERROR_UNKNOWN_COMMAND;
        if ($in_reply == 'BAD_FORMAT')
            return self::ERROR_BAD_FORMAT;
        if ($in_reply == 'DRAINING')
            return $this->mode = self::MODE_DRAINING;
        
        if (!is_array($in_expr_arr))
        {
            $in_expr_arr = array(
                $in_expr_arr => self::OPERATION_OK
            );
        }
        
        foreach ($in_expr_arr as $in_expr => $in_return)
        {
            if (preg_match($in_expr, $in_reply, $writeback))
            {
                array_shift($writeback);
                return $in_return;
            }
        }
        
        return self::ERROR_UNKNOWN_COMMAND;
    }
    
    /**
    * Return a server ID string
    * 
    * Concatenates the IP and port together with a colon to form the server
    * identification string <IP>:<port>.
    * 
    * Useful for storing per-server lists such as the result of aggregate stats commands
    * 
    * @return string server ID string
    */
    public function identify()
    {
        return $this->ip.':'.$this->port;
    }
    
    private function unyaml($in_string)
    {
        if ($this->auto_unyaml)
            return syck_load($in_string);
        return $in_string;
    }
    
    /**
    * Get stats for this server
    * 
    * stats: general stats on basically everything
    * stats-job <job-id>: stats on a certain job
    * stats-tube <tube-name>: stats for a certain tube
    * 
    * These functions all set the $stats writeback variable to the result,
    * if one exists. The return value of the function will indicate whether
    * or not to read thta writeback.
    * 
    * @param reference $stats writeback reference to store the resulting stats
    * @return integer operation status
    */
    public function stats(&$stats)
    {
        if (BeanStalk::DEBUG) echo __METHOD__."\n";
        $this->safe_send_message('stats');
        $res = $this->check_reply($this->safe_read_message(), '/OK (\d+)/', $data);
        if ($res == self::OPERATION_OK)
            $stats = $this->unyaml(trim($this->safe_read_message()));
        else
            $stats = false;
            
        return $res;
    }
    
    public function stats_job($in_job_id, &$stats)
    {
        if (BeanStalk::DEBUG) echo __METHOD__."\n";
        $this->safe_send_message("stats-job {$in_job_id}");
        $res = $this->check_reply($this->safe_read_message(), array(
            '/OK (\d+)/'        => self::OPERATION_OK,
            '/NOT_FOUND/'       => self::ERROR_NOT_FOUND
        ), $data);
        if ($res == self::OPERATION_OK)
            $stats = $this->unyaml(trim($this->safe_read_message()));
        else
            $stats = false;
        return $res;
    }
    
    public function stats_tube($in_tube, &$out_stats)
    {
        if (BeanStalk::DEBUG) echo __METHOD__."\n";
        $this->safe_send_message("stats-tube {$in_tube}");
        $res = $this->check_reply($this->safe_read_message(), array(
            '/OK (\d+)/'        => self::OPERATION_OK,
            '/NOT_FOUND/'       => self::ERROR_NOT_FOUND
        ), $data);
        if ($res == self::OPERATION_OK)
            $out_stats = $this->unyaml(trim($this->safe_read_message()));
        else
            $out_stats = false;
        return $res;
    }
    
    /**
    * The list-tubes command returns a list of all existing tubes. Its form is:
    * 
    * @param reference $out_tubes writeback reference to store the resulting tube list
    * @return integer operation status
    */
    public function list_tubes(&$out_tubes)
    {
        if (BeanStalk::DEBUG) echo __METHOD__."\n";
        $this->safe_send_message("list-tubes");
        $res = $this->check_reply($this->safe_read_message(), '/OK (\d+)/', $data);
        if ($res == self::OPERATION_OK)
            $out_tubes = $this->unyaml(trim($this->safe_read_message($data[0])));
        else
            $out_tubes = false;
        
        return $res;
    }
    
    /**
    * The list-tubes-watched command returns a list of all tubes current being watched. Its form is:
    * 
    * @param reference $out_tubes writeback reference to store the resulting tube list
    * @return integer operation status
    */
    public function list_tubes_watched(&$out_tubes)
    {
        if (BeanStalk::DEBUG) echo __METHOD__."\n";
        $this->safe_send_message("list-tubes-watched");
        $res = $this->check_reply($this->safe_read_message(), '/OK (\d+)/', $data);
        if ($res == self::OPERATION_OK)
            $out_tubes = $this->unyaml(trim($this->safe_read_message($data[0])));
        else
            $out_tubes = false;
        
        return $res;
    }
    
    /**
    * Set the active tube on this server
    * 
    * @param mixed $in_tube tube to switch to
    * @return integer operation status
    */
    public function use_tube($in_tube)
    {
        if (BeanStalk::DEBUG) echo __METHOD__."\n";
        $this->safe_send_message("use {$in_tube}");
        $reply = $this->safe_read_message();
        $res = $this->check_reply($reply, "/USING {$in_tube}/", $data);
        if ($res == self::OPERATION_OK)
        {
            $this->tube = $in_tube;
            $this->recovery['use'] = $this->tube;
        }
        
        return $res;
    }
    
    /**
    * Write a job to this server
    * 
    * @param integer $in_pri job priority
    * @param integer $in_delay delay till job becomes ready
    * @param integer $in_ttr time in seconds that a processor will have to process a job
    * @param mixed $in_job job body
    * @param string $in_tube temporary tube to insert this job into
    * @return integer operation status
    */
    public function put($in_pri, $in_delay, $in_ttr, $in_job, $in_tube=null)
    {
        // If we are draining, NO PUT!
        if ($this->mode == self::MODE_DRAINING)
            return self::MODE_DRAINING;
        
        if ($in_pri < 0 || $in_pri > 4294967294)
            return self::ERROR_BAD_PRIORITY;
        if (!is_numeric($in_delay))
            return self::ERROR_BAD_DELAY;
        if (!is_numeric($in_ttr))
            return self::ERROR_BAD_TTR;
        
        // Switch to another tube first?
        if (!is_null($in_tube))
        {
            $old_tube = $this->tube;
            $this->use_tube($in_tube);
        }
        
        // Do real 'put' here.
        $bytes = strlen($in_job);
        $this->safe_send_message("put {$in_pri} {$in_delay} {$in_ttr} {$bytes}\r\n{$in_job}");
        $real = $this->safe_read_message();
        $res = $this->check_reply($real, array(
            '/INSERTED (\d+)/'          => self::OPERATION_OK,
            '/BURIED (\d+)/'            => self::ERROR_BURIED,
            '/EXPECTED_CRLF/'           => self::ERROR_EXPECTED_CRLF,
            '/JOB_TOO_BIG/'             => self::ERROR_JOB_TOO_BIG
        ), $data);
        
        // Switch back to the original tube
        if (!is_null($in_tube))
            $this->use_tube($old_tube);

        return $res;
    }
    
    /**
    * Express interest in a queue
    * 
    * Add a queue to watch list.
    * 
    * @param string $in_tube tube name to watch
    * @return integer operation status
    */
    public function watch($in_tube)
    {
        if (BeanStalk::DEBUG) echo __METHOD__."\n";
        $this->safe_send_message("watch {$in_tube}");
        $res = $this->check_reply($this->safe_read_message(), '/WATCHING (\d+)/', $data);
        if ($res == self::OPERATION_OK)
        {
            $this->recovery['watch'][$in_tube] = 1;
            unset($this->recovery['ignore'][$in_tube]);
        }

        return $res;
    }
    
    /**
    * Express disinterest in a queue
    * 
    * Remove a queue from watch list.
    * 
    * @param string $in_tube tube name to ignore
    * @return integer operation status
    */
    public function ignore($in_tube, &$out_reply)
    {
        if (BeanStalk::DEBUG) echo __METHOD__."\n";
        $this->safe_send_message("ignore {$in_tube}");
        $rm = $this->safe_read_message();
        $res = $this->check_reply($rm, array(
            '/WATCHING (\d+)/'  => self::OPERATION_OK,
            '/NOT_IGNORED/'     => self::ERROR_LAST_TUBE
        ), $data);
        $out_reply = $data[0];
        if ($res == self::OPERATION_OK)
        {
            $this->recovery['ignore'][$in_tube] = 1;
            unset($this->recovery['watch'][$in_tube]);
        }
        
        return $res;
    }
    
    /**
    * Bury a job
    * 
    * Push the job down into the buried queue, only able to be recovered using the 
    * kick command.
    * 
    * @param integer $in_job_id job to bury
    * @param integer $in_pri new job priority
    * @return boolean operation status
    */
    public function bury($in_job_id, $in_pri)
    {
        if (BeanStalk::DEBUG) echo __METHOD__."\n";
        $this->safe_send_message("bury {$in_job_id} {$in_pri}");
        $res = $this->check_reply($this->safe_read_message(), array(
            '/BURIED/'          => self::OPERATION_OK,
            '/NOT_IGNORED/'     => self::ERROR_LAST_TUBE
        ), $data);
        return $res;
    }
    
    /**
    * Kick some jobs into the ready queue
    * 
    * @param integer $in_upper_bound max number of jobs to kick
    * @return integer operation status
    */
    public function kick($in_upper_bound)
    {
        if (BeanStalk::DEBUG) echo __METHOD__."\n";
        $this->safe_send_message("kick {$in_upper_bound}");
        $res = $this->check_reply($this->safe_read_message(), '/KICKED (\d+)/', $data);
        return $res;
    }
    
    /**
    * Reserve a job
    * 
    * This method blocks while waiting for a job to become available. Once a new job is 
    * received, it is instantiated into a BeanQueueJob and returned. Otherwise false.
    * 
    * @return BeanQueueJob or false
    */
    public function reserve()
    {
        if (BeanStalk::DEBUG) echo __METHOD__."\n";
        $this->safe_send_message("reserve");
        $real = $this->safe_read_message();
        $res = $this->check_reply($real, array(
            '/RESERVED (\d+) (\d+)/'    => self::OPERATION_OK,
            '/DEADLINE_SOON/'           => self::ERROR_DEADLINE_SOON
        ), $data);
        if ($res == self::OPERATION_OK)
        {
            $jid = $data[0];
            $bytes = $data[1];
            $job = $this->safe_read_message($bytes,2);
            return BeanQueueJob::open($this, $jid, $job);
        }   
        return $res;
    }
    
    /**
    * Reserve a job, with a timeout
    * 
    * This method blocks while waiting for a job to become available. Once a new job is 
    * received, it is instantiated into a BeanQueueJob and returned. Otherwise false.
    * 
    * @return BeanQueueJob or false
    */
    public function reserve_with_timeout($in_timeout=0)
    {
        if (BeanStalk::DEBUG) echo __METHOD__."\n";
        $this->safe_send_message("reserve-with-timeout {$in_timeout}");
        $real = $this->safe_read_message();
        $res = $this->check_reply($real, array(
            '/RESERVED (\d+) (\d+)/'    => self::OPERATION_OK,
            '/DEADLINE_SOON/'           => self::ERROR_DEADLINE_SOON,
            '/TIMED_OUT/'               => self::ERROR_TIMED_OUT
        ), $data);
        if ($res == self::OPERATION_OK)
        {
            $jid = $data[0];
            $bytes = $data[1];
            $job = $this->safe_read_message($bytes,0);
            return BeanQueueJob::open($this, $jid, $job);
        }   
        return $res;
    }
    
    /**
    * Release a job
    * 
    * Releases a job that has been reserved on this server, by ID. Usually called by 
    * BeanStalk::release(&BeanQueueJob)
    * 
    * @param integer $in_job_id job id to delete
    * @return integer operation status
    */
    public function release($in_job_id, $in_pri, $in_delay)
    {
        if (BeanStalk::DEBUG) echo __METHOD__."\n";
        $this->safe_send_message("release {$in_job_id} {$in_pri} {$in_delay}");
        $res = $this->check_reply($this->safe_read_message(), array(
            '/RELEASED/'        => self::OPERATION_OK,
            '/BURIED/'          => self::ERROR_BURIED,
            '/NOT_FOUND/'       => self::ERROR_NOT_FOUND
        ), $data);
        return $res;
    }
    
    /**
    * Delete a job
    * 
    * Removes a job from this server by ID. Usually called by BeanStalk::delete(&BeanQueueJob)
    * 
    * @param integer $in_job_id job id to delete
    * @return integer operation status
    */
    public function delete($in_job_id)
    {
        if (BeanStalk::DEBUG) echo __METHOD__."\n";
        $this->safe_send_message("delete {$in_job_id}");
        $res = $this->check_reply($this->safe_read_message(), array(
            '/DELETED/'         => self::OPERATION_OK,
            '/NOT_FOUND/'       => self::ERROR_NOT_FOUND
        ), $data);
        return $res;
    }
    
    public function peek($in_job_id, &$in_writeback=null)
    {
        if (BeanStalk::DEBUG) echo __METHOD__."\n";
        $this->safe_send_message("peek {$in_job_id}");
        $res = $this->check_reply($this->safe_read_message(), array(
            '/FOUND (\d+) (\d+)/'   => self::OPERATION_OK,
            '/NOT_FOUND/'           => self::ERROR_NOT_FOUND
        ), $data);
        $in_writeback = false;
        if ($res == self::OPERATION_OK)
        {
            $job = $this->safe_read_message($data[1]);
            if (!is_null($in_writeback))
                $in_writeback = $job;
        }
        return $res;
    }
    
    public function peek_ready(&$in_writeback=null)
    {
        $this->safe_send_message("peek-ready");
        $res = $this->check_reply($this->safe_read_message(), array(
            '/FOUND (\d+) (\d+)/'   => self::OPERATION_OK,
            '/NOT_FOUND/'           => self::ERROR_NOT_FOUND
        ), $data);
        $in_writeback = false;
        if ($res == self::OPERATION_OK)
        {
            $job = $this->safe_read_message($data[1]);
            if (!is_null($in_writeback))
                $in_writeback = $job;
        }
        return $res;
    }
    
    public function noop()
    {
        if (BeanStalk::DEBUG) echo __METHOD__."\n";
        $res = false;
        stream_set_blocking($this->socket, 1);
        usleep(1500);
        $this->alive();
        stream_set_blocking($this->socket, 0);
        return $res;
    }
    
    /**
    * Check connection
    * 
    * If not yet connected, attempt to do so. Else, check that the socket is still
    * valid.
    * 
    * @return boolean connection status
    */
    public function alive($in_method = 'slow', $in_employ_lastcommand=true)
    {
        if (!$this->socket || @feof($this->socket))
        {
            $this->socket = false;
            $conn = false;
            
            while ($this->max_retries == -1 || $this->retries < $this->max_retries)
            {
                $conn = $this->connect($in_employ_lastcommand);
                if ($conn === true)
                    break;
                
                sleep(1);
                if ($this->max_retries == -1 && $in_method == 'fast')
                    break;
            }
            if ($conn === false)
                throw new BeanQueueCouldNotConnectException;
            return ($conn !== false);
        }
        
        return true;
    }
    
    /**
    * Attempt to connect to beastalkd
    * 
    * Tries to establish a tcp socket connection to the beastalkd server. If this server was 
    * already tried too many times, simply returns false;
    * 
    * @return boolean connection result
    */
    private function connect($in_employ_lastcommand=true)
    {
        if ($this->max_retries != -1 && $this->retries >= $this->max_retries)
            return false;
            
        $this->retries++;
        $this->socket = @fsockopen($this->ip, $this->port, $errno, $errstr, $this->timeout);
        $this->preparing = true;
        if ($this->socket && !feof($this->socket))
        {
            stream_set_blocking($this->socket, 0);
            $this->mode = self::MODE_NORMAL;
            
            if ($this->recovery)
            {
                if ($this->recovery['use'])
                    $this->use_tube($this->recovery['use']);
                if (sizeof($this->recovery['watch']))
                    foreach ($this->recovery['watch'] as $watched => $tr)
                        $this->watch($watched);
                
                if (sizeof($this->recovery['ignore']))
                    foreach ($this->recovery['ignore'] as $ignored => $tr)
                        $this->ignore($ignored,$trash);
                
                if (isset($this->recovery['lastcommand']))
                {
                    $lc = $this->recovery['lastcommand'];
                    unset($this->recovery['lastcommand']);
                    if ($in_employ_lastcommand)
                        $this->safe_send_message($lc);
                }
            }
            $this->preparing = false;
            return true;
        }
        $this->preparing = false;
        return false;
    }
    
    /**
    * Pseudo blocking socket read
    * 
    * This method maintains an internal buffer and allows a nonblocking socket to be read 
    * in a blocking fashion, giving back a message at a time.
    * 
    * @param mixed $in_buf_size max amount of bytes to read in each call to fread
    * @return string message
    */
    private function read_message($in_buf_size=256, $in_operation_timeout=-1)
    {
        stream_set_blocking($this->socket, 0);
        $in_buf_size += strlen(self::MSG_DELIM);
        $no_packet = true;         // Start off trying to hit up the buffer
        $to = microtime(true) + $in_operation_timeout;
        do
        {
            if ($this->rbuflen < self::MAX_READ_BUF || $no_packet)
            {
                // read new data if we are under the read buffer size or
                // if we couldnt find a complete message in the buffer last
                // time
                
                $no_packet = false;
                $data = fread($this->socket,$in_buf_size);
                
                if ($data === false)
                    return self::READ_ERROR;
                
                if ($tbuflen = strlen($data))      // Got something. Put it in the buffer.
                {
                    $this->rbuf .= $data;
                    $this->rbuflen += $tbuflen;
                }
            }
            
            if ($this->rbuflen && ($pos = strpos($this->rbuf, self::MSG_DELIM, 0)) !== false)
            {                
                // Found a packet
                $wanted_packet = substr($this->rbuf,0,$pos);
                $seek = $pos+strlen(self::MSG_DELIM);
                $this->rbuf = substr($this->rbuf,$seek);
                if (strlen($wanted_packet))
                {
                    $this->rbuflen -= $seek;
                    if (BeanStalk::DEBUG)
                        echo __METHOD__."({$wanted_packet})\n";
                        
                    return $wanted_packet;
                }
            }
            
            $no_packet = true;
        } while ($tbuflen);
        
        return self::READ_ERROR;
    }
    
    private function safe_read_message($in_buf_size=256, $in_operation_timeout=2)
    {
        $in_buf_size = (!$in_buf_size) ? 256 : $in_buf_size;
        $to = microtime(true) + $in_operation_timeout;
        do
        {
            $read = $this->read_message($in_buf_size, 2);
            
            if ($read !== self::READ_ERROR)
                break;
            
            if ($in_operation_timeout > 0)
            {
                if (microtime(true) >= $to)
                {
                    $this->noop();
                    $to = microtime(true) + $in_operation_timeout;
                    sleep(1);
                }
            }
        } while (1);
        
        return $read;
    }
    
    private function safe_send_message($in_message)
    {
        if (!$this->preparing)
            $this->recovery['lastcommand'] = $in_message;
            
        do
        {
            $sent = $this->send_data($in_message);
            if ($sent === false)
                $connected = $this->alive('slow',false);
            else
                break;
        } while (1);
        
        return $sent;
    }
    
    /**
    * Write to the server
    * 
    * @param mixed $in_message message to send
    * @return integer bytes read or boolean false on error
    */
    private function send_data($in_message)
    {
        if (BeanStalk::DEBUG)
            echo __METHOD__."({$in_message})\n";
        $tosend = $in_message.self::MSG_DELIM;
        $tl = strlen($tosend);
        $b = @fwrite($this->socket,$tosend,$tl);
        return $b;
    }
    
    private function log($in_message)
    {
        if ($this->log === false) return;
        $f = fopen($this->log,'a');
        $in_message = "[".posix_getpid()."] {$in_message}\n";
        @fwrite($f,$in_message,strlen($in_message));
        fclose($f);
    }
    
    private function say($in_message)
    {
        echo date('H:i:s')." ".$in_message."\n";
    }
}

/**
* Class: BeanQueueJob
* 
* Returned by BeanQueue::reserve()
* 
* @package BeanQueueJob
*/
class BeanQueueJob
{
    
    private $alive;             // Job is able to take commands
    private $jid;               // Job ID
    private $payload;           // Job data
    private $server;            // Reference to owning server
    
    /**
    * Private BeanQueueJob constructor
    * 
    * @param BeanQueue $in_server reference to the server that owns this job
    * @param integer $in_job_id job id
    * @param mixed $in_job_payload job body
    * @return BeanQueueJob
    */
    private function __construct(&$in_server, $in_job_id, $in_job_payload)
    {
        $this->server = $in_server;
        $this->jid = $in_job_id;
        $this->payload = $in_job_payload;
        $this->alive = true;
    }
    
    /**
    * BeanQueueJob factory
    * 
    * @param BeanQueue $in_server reference to the server that owns this job
    * @param integer $in_job_id job id
    * @param mixed $in_job_payload job body
    * @return BeanQueueJob
    */
    public static function open(&$in_server, $in_job_id, $in_job_payload)
    {
        $f = false;
        if ($in_server->alive() !== true)
            return $f;
            
        return new BeanQueueJob($in_server, $in_job_id, $in_job_payload);
    }
    
    /**
    * Check if job is still alive
    * 
    * @return boolean status of this job
    */
    public function alive()
    {
        return $this->alive;
    }
    
    /**
    * Delete this job
    * 
    * Called when a job has been executed. This calls back to the owning server
    * and deletes the job from memory
    * 
    * @return integer operation status
    */
    public function delete()
    {
        if ($this->alive !== true)
            return true;
        $this->alive = false;
        return $this->server->delete($this->jid);
    }
    
    /**
    * Release this job
    * 
    * Called when the client wishes to send the job back into the ready queue, incomplete.
    * Calls back to the owning server and releases the job there.
    * 
    * @param integer $in_pri new priority level
    * @param integer $in_delay seconds until job will be ready
    * @return interger operation status
    */
    public function release($in_pri, $in_delay)
    {
        if ($this->alive !== true)
            return true;
        $this->alive = false;
        return $this->server->release($this->jid, $in_pri, $in_delay);
    }
    
    /**
    * Bury this job
    * 
    * Sends the job into the bury queue. Can only be unearthed with the kick command from 
    * that same server.
    * 
    * @param integer $in_pri new priority level
    * @return integer operation status
    */
    public function bury($in_pri)
    {
        if ($this->alive !== true)
            return true;
        $this->alive = false;
        return $this->server->bury($this->jid, $in_pri);
    }
    
    /**
    * Retrieve this job's payload
    * 
    * @return mixed job payload
    */
    public function get()
    {
        return $this->payload;
    }
    
    /**
    * Retrieve the job id
    * 
    * @return integer job id
    */
    public function get_jid()
    {
        return $this->jid;
    }
    
    public static function check($in_check_job)
    {
        $c = __CLASS__;
        if ($in_check_job instanceof $c)
            return true;
        return false;
    }
    
}

class BeanQueueNoServersSuppliedException extends Exception{}
class BeanQueueNoValidServersException extends Exception{}
class BeanQueueInvalidServerException extends Exception{}
class BeanQueueInvalidSelectorBadReserverException extends Exception{}
class BeanQueueInvalidSelectorBadServerPickerException extends Exception{}
class BeanQueueJobServerDiedException extends Exception{}
class BeanQueueCouldNotConnectException extends Exception{}

?>
