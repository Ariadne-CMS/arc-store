<?php

namespace arc;

class store {
	
    public static function getStore()
    {
        $context = \arc\context::$context;
        return $context->arcStore;
    }

	public static function connect($dsn, $resultHandler=null)
	{
		if (!$resultHandler) {
			$resultHandler = function($result) {
				$data = $result->fetch(\PDO::FETCH_ASSOC);
				while ($data) {
					$value = (object) $data;
					$value->data = json_decode($value->data);
					$value->ctime = strtotime($value->ctime);
					$value->mtime = strtotime($value->mtime);
					$path = $value->path;
					yield $path => $value;
					$data = $result->fetch(\PDO::FETCH_ASSOC);
				}
/*	
				$data = $result->fetchAll(\PDO::FETCH_ASSOC);
				$tree = array_combine( array_column( $data, 'path'), $data);
				array_walk(
					$tree,
					function(&$value, $path) {
						$value = (object) $value;
						$value->data  = json_decode($value->data);
						$value->ctime = strtotime($value->ctime);
						$value->mtime = strtotime($value->mtime);
					}
				);
				return $tree;
*/			};
		}
		$store = new store\PSQLStore(
			new \PDO($dsn), 
			new store\PSQLQueryParser(), 
			$resultHandler
		);
		\arc\context::push([
			'arcStore' => $store
		]);
		return $store;
	}

	public static function disconnect()
	{
        \arc\context::pop();
	}

	public static function cd($path)
	{
		return self::getStore()->cd($path);
	}

	public static function find($query)
	{
		return self::getStore()->find($query);
	}

	public static function parents($path)
	{
		return self::getStore()->parents($path);
	}

	public static function ls($path)
	{
		return self::getStore()->ls($path);
	}

	public static function get($path)
	{
		return self::getStore()->get($path);
	}

	public static function exists($path)
	{
		return self::getStore()->exists($path);
	}
}
