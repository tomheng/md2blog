#!/bin/env php
<?php
/**
 * 
 * using XML RPC of wordpress to add new post
 *
 * @create 2013-06-28
 * @author tomheng<zhm20070928@gmail.com>
 * @license http://www.zend.com/license/3_0.txt   PHP License 3.0
 * @version 1.0.0
 *
*/
require 'config.php';
require 'lib/xmlrpc/xmlrpc.php';
require 'lib/markdown.php';
use \Michelf\Markdown;
if(substr(php_sapi_name(), 0, 3) != 'cli'){
	quit("This Programe can only be run in CLI mode");
}

if($argc == 2 && in_array($argv[1], array('--help', '-help', '-h', '-?'))){
	quit('using XML RPC of wordpress to add new post');
}

$markdown_filepath = $argv[1];
if(stripos($markdown_filepath, '.') !== 0 && $config['markdown_file_dir']){
	$markdown_filepath = $config['markdown_file_dir'].DIRECTORY_SEPARATOR.$markdown_filepath;
}
$markdown_filepath = realpath($markdown_filepath);
if(!file_exists($markdown_filepath)){
	quit("can not find file {$markdown_filepath}");
}

$fp = fopen($markdown_filepath, 'r+');
if(!$fp){
	quit("can not open file {$markdown_filepath}");
}
$post = array(
	'post_content' => '',
	'post_title' => '',
	'post_status' => $config['post_status'],
	'post_author' => $config['post_author'],
	'comment_status' => $config['comment_status'],
	'ping_status' => $config['ping_status'],
	'sticky' => '',
	/*'custom_fields' => array(),
	'terms' => array(),
	'terms_names' => array(),
	'enclosure' => array(),*/
);
while(($line = fgets($fp)) !== false){
	if(strlen(trim($line)) == 0 || $post['post_content']){
		$post['post_content'] .= $line;
		continue;
	}
	if(($pos = stripos($line, ':')) == false){
		continue;
	}	
	$key = strtolower(substr($line, 0, $pos));
	$value = substr($line, $pos+1);
	if(in_array($key, array('category', 'cat', 'categories'))){
		$post['terms_names'] = array(
			array('taxonomy_name' => preg_split('/[,，、]/i ', $value)),
			'struct'	
		);	
	}
	if(!isset($post[$key])){
		$key = 'post_'.$key;
	}
	$post[$key] = $value;
}

if(!feof($fp)){
	quit('an unexpected error occur');
}
if(!$post['post_title'] || !$post['post_content']){
	quit('markdown file must have title file and content');	
}

//upload local image file to remote media repository
$regex = '#(?<=(\(|:){1})\s?[^\)\s]*\.(?:jpg|jpeg|gif|png)(?=(\)|\s){1})#ie';
$replacement = "upload_image('$0')";
$post['post_content'] = preg_replace($regex, $replacement, $post['post_content'], -1, $upload_img_count);
$raw_content = $post['post_content'];
$post['post_content'] = Markdown::defaultTransform($post['post_content']);
$re = publish_blog($post);
if(!$re){
	quit('Failed to publish post, please try again.');
}elseif(!isset($post['post_id']) || $upload_img_count > 0){
	$post['post_id'] = $re;
	$md_content = '';
	unset($post['post_content']);
	foreach($post as $key => $value){
		$key = str_replace('post_', '', $key);
		$md_content .= $key.': '.$value.PHP_EOL;	
	}
	$md_content .= $raw_content;
	rewind($fp);
	fwrite($fp, $md_content);
}
fclose($fp);

//publish
function publish_blog($data){
	global $config;
	$method = 'wp.newPost';
	$xmlrpc = xmlrpc();
	$request = array(
		$config['blog_id'], //blogid
		$config['username'],//username
		$config['password'],//password
	);
	if(isset($data['post_id']) && $data['post_id']	> 0){
		$request[] = $data['post_id'];
		unset($data['post_id']);
		$method = 'wp.editPost';
	}
	$request[] = array($data, 'struct');	
	$xmlrpc->method($method);
	$xmlrpc->request($request);
	if(!$xmlrpc->send_request()){
		quit($xmlrpc->display_error());
	}
	$re = $xmlrpc->display_response();
	return $re;
}

//upload image
function upload_image($img_path){
	$img_path = trim($img_path);
	$scheme = parse_url($img_path, PHP_URL_SCHEME);
	if($scheme == 'http' || $scheme == 'https' || ($scheme && $scheme != 'file')){
		return $img_path;	
	}
	//$img_path = realpath($img_path);
	if(!file_exists($img_path)){
		return $img_path;
	}
	show_tips("start upload $img_path");
	$image_info = getimagesize($img_path);
	$image_data = file_get_contents($img_path);
	global $config;
	$method = 'wp.uploadFile';
	$xmlrpc = xmlrpc();
	$xmlrpc->method($method);	
	$data = array(
		array(
			'name' => pathinfo($img_path, PATHINFO_BASENAME),
			'type' => $image_info['mime'], 
			'bits' => array($image_data, 'base64'),
			'overwrite' => array($config['media_overwrite'], 'boolean'),
		),
		'struct'
	); 
	$request = array(
		$config['blog_id'], //blogid
		$config['username'],//username
		$config['password'],//password
		$data			
	);
	$xmlrpc->request($request);
	if(!$xmlrpc->send_request()){
		quit($xmlrpc->display_error());
	}
	$re = $xmlrpc->display_response();
	return $re['url'];
}

//xmlrpc client instance
function xmlrpc(){
	static $instance = null;
	if($instance == null){
		global $config;
		if(!$config['xmlrpc_server'] || !$config['xmlrpc_port']){
			quit('Please set RPC Server address and port');
		}
		$instance = new CI_Xmlrpc();
		$instance->server($config['xmlrpc_server'], $config['xmlrpc_port']);
		//$instance->set_debug();
	}
	return $instance;
}
// exit 
function quit($msg, $exit_code = 0){
	echo $msg.PHP_EOL;
	exit($exit_code);	
}

//progress
function show_tips($msg){
	echo $msg.PHP_EOL;
}
