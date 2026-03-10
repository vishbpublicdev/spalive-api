<?php

use Cake\Core\Configure;
use Cake\Utility\Text;

function label($param){
    $result = '-';

    switch ($param) {
        case 'appname': $result = 'SpaLiveMD'; break;
        case 'title': $result = 'SpaLiveMD'; break;
    }

    return $result;
}

function get2($param, $default = false){
    if(Configure::read('debug') === true){
        $result = isset($_POST[$param])? $_POST[$param] : (isset($_GET[$param])? $_GET[$param] : false);
    }else{
        $result = isset($_POST[$param])? $_POST[$param] : false;
    }

	return $result !== false? trim($result) : $default;
}

function get($param, $default = false){
    $data = Data::post();

    // print_r($data);exit;
    if(isset($data[$param])){
        $result = $data[$param];
    }elseif(isset($_POST[$param])){
        $result = $_POST[$param];
    }elseif(isset($_GET[$param])){
        $result = $_GET[$param];
    }else{
        $result = $default;
    }

    return $result;
}

function dtrim($value, $default = false){
	return empty($value)? '' : trim($value);
}

function serialize_name($str_name, $replacement = '-') {
	return strtolower(Text::slug($str_name, $replacement));
}

function generate_password(){
    return bin2hex(openssl_random_pseudo_bytes(3));
}

function validate_email($email){
    $email = trim($email);

    if(!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    else return true;
}

function validate_int($value){
    if($value === null) return null;

    if(is_numeric($value)) return true;
    else return false;

}

function validate_string($value, $length = false){
    if($value === null) return false;
    $value = trim($value);

    if($length !== false && strlen($value) < $length) return false;
    else return true;

}

class Data {
    static $post = null;
    static $array_errors = array();

    public static function __data($array, $prefix = ''){
        $result = [];

        if(!is_array($array)){
            return [];
        }

        foreach ($array as $key => $value) {
            if(is_array($value)){
                // print_r($value);exit;
                $result = array_merge($result, self::__data($value, "{$key}."));
            }else{
                $result["{$prefix}{$key}"] = $value;
            }
        }

        return $result;
    }

    public static function post(){
        if(self::$post == null){
            self::$post = self::__data(json_decode(file_get_contents('php://input'), true));
        }

        return self::$post;
    }

    public static function ifadd(&$data, $key, $value){
        $key = trim($key);

        if($value !== null){
            $value = trim($value);

            $data[$key] = $value;
        }
    }

    public static function in_array($value, $array, $error){
        if($value === null) return null;
        $value = trim($value);

        if(!in_array($value, $array)){
            self::$array_errors[] = $error === false? 'Valor invalido.' : $error;
        }

        return $value;
    }

    // public static function is_valid_date($str_date, $format = 'Y-m-d'){
    //     return $str_date == str_date($format, strtotime($date));
    // }

    public static function date($value, $format = 'Y-m-d', $error = false){
        if($value === null) return null;
        $value = trim($value);

        $timestamp = strtotime($value);
        if(!empty($timestamp)){
            $value = date($format, $timestamp);
        }else{
            self::$array_errors[] = $error === false? 'Fecha invalida.' : $error;
        }

        return $value;
    }

    public static function email($email, $error = false){
        $email = trim($email);

        if(!filter_var($email, FILTER_VALIDATE_EMAIL)){
            self::$array_errors[] = $error === false? 'Correo invalido.' : $error;
        }

        return $email;
    }


    public static function int($value, $error = false){
        if($value === null) return null;

        if(is_numeric($value)){
            $value = intval($value);
        }else{
            self::$array_errors[] = $error === false? 'Numero invalido.' : $error;
        }

        return $value;
    }

    public static function boolean($key){
        $value = get($key, '');

        return intval($value);
    }

    public static function double($value, $error = false){
        if($value === null) return null;

        $value = is_string($value) && empty($value)? 0 : $value;
        $value = preg_replace('/[^0-9.]/', '', $value);

        if(is_double($value + 0.0)){
            $value = doubleval($value);
        }else{
            self::$array_errors[] = $error === false? 'Numero invalido.' : $error;
        }

        return $value;
    }

    public static function string($value, $length = false, $error = false){
        if($value === null) return null;
        $value = trim($value);

        if($length !== false && strlen($value) < $length){
            self::$array_errors[] = $error === false? 'Cadena invalida.' : $error;
        }

        return $value;
    }

    public static function add_error($str_error){
        self::$array_errors[] = $str_error;
    }

    public static function get_errors(){
        return self::$array_errors;
    }

    public static function is_valid(){
        return empty(self::$array_errors)? true : false;
    }
}