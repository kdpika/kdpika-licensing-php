<?php
declare(strict_types=1);
$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/';
if(PHP_SAPI==='cli-server'&&str_starts_with($path,'/assets/')&&!str_contains($path,'..')&&is_file(__DIR__.$path))return false;
if(PHP_VERSION_ID<80200||PHP_INT_SIZE<8||!extension_loaded('curl')||!extension_loaded('sodium')){http_response_code(503);header('Content-Type: text/plain; charset=utf-8');exit('This example needs 64-bit PHP 8.2+ with curl and sodium. For printable ZIP packs also enable zip in your hosting PHP settings.');}
require dirname(__DIR__).'/bootstrap.php';
if($path==='/'){header('Location: /sudoku-studio',true,302);exit;}
if(\App\Controllers\SudokuStudioController::handles($path))(new \App\Controllers\SudokuStudioController())->serve($path);
http_response_code(404);echo 'Page not found.';
