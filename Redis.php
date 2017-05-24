<?php
namespace Core;

class Redis {	
	protected $config;
	
	protected $redis;
	
	protected static $_instance = null;
	
	protected function __construct() {
		$config = Config::get('common.redis');
		$this->redis = new \Redis();
		if($config['pconnect']) {
			$this->redis->pconnect($config['host'], $config['port'], $config['timeout']);
                        $this->redis->auth($config['auth']);
		} else {
			$this->redis->connect($config['host'], $config['port'], $config['timeout']);
                        $this->redis->auth($config['auth']);
		}

		$this->config = $config;
	}
	
	public static function getInstance() {
		if(!static::$_instance) {
			static::$_instance = new static;
		}
		
		return static::$_instance;
	}
	
	protected function K($key) {
		return $this->config['prefix'] . $key;
	}
	protected function L($key) {
                return $this->config['lunxun'] . $key;
        }
	protected function V($data, $expire = 0) {
		$expire = intval($expire);
		if($expire > 0) {
			$expire += time(); 
		}
		
		return $expire .'|'. serialize($data);
	}
	
	protected function _V($data) {
		if(!$data) return $data;
		
		list($expire, $data) = explode('|', $data, 2);
		if($expire > 0 && $expire < time()) {
			return false;
		}
		
		return unserialize($data);
	}
	
	/**
	 * get
	 */
	public function get($key) {
		$data = $this->redis->get($this->K($key));		
		return $this->_V($data);
	}
	
	/**
	 * set
	 */
	public function set($key, $value, $expire = 0) {
		return $this->redis->set($this->K($key), $this->V($value, $expire));
	}
	
	/**
	 * hget
	 */
	public function hget($key, $field) {
		$data = $this->redis->hget($this->K($key), $field);
		return $this->_V($data);
	}
	public function lget($key, $field) {
                $data = $this->redis->hget($this->L($key), $field);
                return $data;
        }
	/**
	 * hset
	 */
	public function hset($key, $field, $value, $expire = 0) {
		return $this->redis->hset($this->K($key), $field, $this->V($value, $expire));
	}
	public function lset($key, $field, $value, $expire = 0) {
                return $this->redis->hset($this->L($key), $field,$value);
        }
	/**
	 * hsetnx
	 */
	public function hsetnx($key, $field, $value, $expire = 0) {
		return $this->redis->hsetnx($this->K($key), $field, $this->V($value, $expire));
	}

	public function hincrby($key, $field, $value, $expire = 0) {
		return $this->redis->hincrby($this->K($key), $field, $value);
	}
	public function lincrby($key, $field, $value, $expire = 0) {
                return $this->redis->hincrby($this->L($key), $field, $value);
        }
	/**
	 * hdel
	 */
	public function hdel($key, $field) {
		return $this->redis->hdel($this->K($key), $field);
	}
	
	/**
	 * lpush,将数据加入到表头
	 */
	public function lpush($key, $value, $expire = 0) {
		return $this->redis->lpush($this->K($key), $this->V($value, $expire));
	}
	
	/**
	 * lpop,移除并返回表头的数据
	 */
	public function lpop($key) {
		$data = $this->redis->lpop($this->K($key));
		return $this->_V($data);
	}
	
	/**
	 * rpush,将数据加入到表尾
	 */
	public function rpush($key, $value, $expire = 0) {
		return $this->redis->rpush($this->K($key), $this->V($value, $expire));
	}
	
	/**
	 * rpop,移除并返回表尾的数据
	 */
	public function rpop($key) {
		$data = $this->redis->rpop($this->K($key));		
		return $this->_V($data);
	}

	/**
	 * 等待锁
	 */
	public function lock($key) {
		do{
			$isLock = $this->setLock($key);
			if($isLock) break;
			
			usleep(200000);
		} while(true);
	}
	
	/**
	 * 加锁
	 * @return true:成功，false:失败
	 */
	public function setLock($key) {
		return $this->hsetnx('lock', $key, true);
	}
	
	/**
	 * 解锁
	 */
	public function unlock($key) {
		return $this->hdel('lock', $key);
	}
}
