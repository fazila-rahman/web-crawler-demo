<?php
/**
 * This script provides Helper methods to the Crawler Controller
 * Copyright (C) 2022
 * 
 * @author Fazilatur Rahman
 */

use Illuminate\Support\Facades\Auth;

if(!function_exists('page_hash')){
    function page_hash($file_path){
        return hash_file('crc32b', $file_path);
    }
}

if(!function_exists('get_domain_name')){
    function get_domain_name($seed){
        $result = parse_url($seed);
  
        $host_names = explode(".", $result['host']);
        return $host_names[count($host_names)-2] . "." . $host_names[count($host_names)-1];
    }
}

if(!function_exists('sanitize_link')){
    function sanitize_link($l, $url){
        if (substr($l, 0, 1) == "/" && substr($l, 0, 2) != "//") {
            $l = parse_url($url)["scheme"]."://".parse_url($url)["host"].$l;
        } else if (substr($l, 0, 2) == "//") {
            $l = parse_url($url)["scheme"].":".$l;
        } else if (substr($l, 0, 2) == "./") {
            $l = parse_url($url)["scheme"]."://".parse_url($url)["host"].dirname(parse_url($url)["path"]).substr($l, 1);
        } else if (substr($l, 0, 1) == "#") {
            //$l = parse_url($url)["scheme"]."://".parse_url($url)["host"].parse_url($url)["path"].$l;
        } else if (substr($l, 0, 3) == "../") {
            $l = parse_url($url)["scheme"]."://".parse_url($url)["host"]."/".$l;
        } else if (substr($l, 0, 11) == "javascript:") {
            //Ignore
        } else if (substr($l, 0, 5) != "https" && substr($l, 0, 4) != "http") {
            $l = parse_url($url)["scheme"]."://".parse_url($url)["host"]."/".$l;
        }
        return $l;
    }
}