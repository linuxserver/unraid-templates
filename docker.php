<?php
/**
 * Aesir
 *
 * A redesigned WebGUI for unRAID
 *
 * @package     Aesir
 * @author      Kode
 * @copyright   Copyright (c) 2015 Kode (admin@coderior.com)
 * @link        http://coderior.com
 * @since       Version 1.0
 */

 // --------------------------------------------------------------------

/**
 * unRAID template feed builder for use with Aesir
 *
 * Class to traverse github to grab all the templates
 *
 * @package     Aesir
 * @subpackage  Classes
 * @author      Kode
 */

$docker = new Docker;
$docker->build_list();


class Docker {

	public $github_username = ''
	public $github_password = ''
	public $filename = '/home/fanart/www/webservice/unraid/apps.json';

	public $requests = 0;
	public $apps = 0;
	public $applist = array();

	public function build_list() {
		//ini_set('max_execution_time', 1200);

		$filename = $this->filename;
		$output=array('apps'=>'0', 'requests' => 0);
		$current_time = time(); $expire_time = 1 * 60 * 60; $file_time = filemtime($filename);
		if(file_exists($filename) && ($current_time - $expire_time < $file_time)) {
			//echo 'returning from cached file';
			header("HTTP/1.1 200 OK");
			header("Content-Type: application/json; charset=utf-8");
			echo file_get_contents($filename);
		} else {
			$url = 'https://raw.githubusercontent.com/Squidly271/repo.update/master/Repositories.json';
			$repos = json_decode( $this->get_content_from_github( $url ) );
			$json = array();
			//$repos = array( $repos[0] ); // comment out after testing
			foreach( $repos as $repo ) {
				$file = $repo->url;
				$split = explode( '/',$file );
				$split6 = (isset($split[6])) ? $split[6] : 'master';
				$treeurl = 'https://api.github.com/repos/'.$split[3].'/'.$split[4].'/git/trees/'.$split6;
				$trees = json_decode( $this->get_content_from_github( $treeurl ) );
				$this->requests++;
				$this->tree_traverse( $trees, $repo->forum );
			}
			$output['apps'] = $this->apps;
			$output['requests'] = $this->requests;
			$output['applist'] = $this->applist;

			$jsonfile = json_encode( $output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			file_put_contents( $filename, $jsonfile );
		}
	}

	public function tree_traverse( $file_list, $forum ) {
		foreach( $file_list->tree as $entry ) {
			$fname = explode(".", $entry->path);
			$fname = end($fname);
			if( $entry->type == 'blob' && $fname == 'xml' ) {
				$this->apps++;
				$this->applist[] = $this->xmldata( $entry->url, $forum );
			} elseif( $entry->type == 'tree' ) {
				$trees = json_decode( $this->get_content_from_github( $entry->url ) );
				$this->requests++;
				$this->tree_traverse( $trees, $forum );
			}
		}
	}

	public function xmldata( $url, $forum ) {
		$filea = json_decode( $this->get_content_from_github( $url ) );
		$this->requests++;
		$fileContents= base64_decode( $filea->content );
		$fileContents = str_replace(array("\n", "\r", "\t"), '', $fileContents);
		$fileContents = trim(str_replace('"', "'", $fileContents));
		$simpleXml = simplexml_load_string($fileContents);

		$simpleXml->Forum = $forum;
		$simpleXml->Support = ( isset( $simpleXml->Support ) && !empty( $simpleXml->Support ) ) ? $simpleXml->Support : $forum;
		$simpleXml->TemplatePath = $url;
		$key = 'base_images_'.$simpleXml->Registry;
		$result = $this->memcacheget( $key );
		if( empty( $result ) ) {  
			$repo = $simpleXml->Registry;
			$repo = ( substr( $repo, -1) !== '/') ? $repo.'/' : $repo;
			$page_data2 = @file_get_contents($repo.'dockerfile/raw');
			if( $page_data2 ) {
				$dockerfile = explode("\n",$page_data2);
				foreach( $dockerfile as $line ) {
					if( strpos( $line, 'FROM' ) !== false ) {
						$base_image = str_replace( 'FROM ', '', $line );
						$base_image = trim( $base_image );
						break;
					}
				}
			} else {
				$base_image = 'unknown';
			}
			$this->memcacheset( $key, $base_image);
		} else {
			$base_image = $result;
		}
		$simpleXml->Base = $base_image;
		return $simpleXml;
	}

	public function memcacheget($key){
		$memcache = new Memcache;
		$memcache->connect('localhost', 11211);
		$result = $memcache->get($key);
		return $result;
	}

	public function memcacheset($key,$value,$timeout=86400){
		$memcache = new Memcache;
		$memcache->connect('localhost', 11211);
		$result = $memcache->get($key);
		if(empty($result)){  //store in memcache
			$memcache->set($key,$value,MEMCACHE_COMPRESSED,$timeout);
		} else {
			$memcache->replace($key,$value,MEMCACHE_COMPRESSED,$timeout);
		}
		return $result;
	}


	public function get_content_from_github($url) {
		$username=$this->github_username;
		$password=$this->github_password;
		$ch = curl_init();
		curl_setopt($ch,CURLOPT_URL,$url);
		curl_setopt($ch,CURLOPT_RETURNTRANSFER,1); 
		curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,1);
		curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
		curl_setopt($ch,CURLOPT_USERAGENT,'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.13) Gecko/20080311 Firefox/2.0.0.13');
		$content = curl_exec($ch);
		curl_close($ch);
		return $content;
	}




}
