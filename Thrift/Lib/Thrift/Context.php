<?php
namespace Thrift;
Class Context{
    public static function init(){
        global $context;
        $context = array();
    }

    public static function put($key,$value){
        global $context;
        $context[$key] = $value;       
    }
    public static function get($key = null){
        global $context;
        if ($key){
            if(isset($context[$key])){
                return $context[$key] ;
            }
            else{
                return null;
            }
        }else{
            return $context;
        }
    }
    
    public static function delete($key){
        global $context;
        if ($key){
           unset($context[$key]) ;
        }
    }
    public static function clear(){
        global $context;
        $context = array();
    }       
}