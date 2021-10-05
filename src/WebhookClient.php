<?php
namespace Server;


class WebhookClient {
    public array $headers = [];
    public array $data = [];
    public array $file = [];


    public function listen_headers()
    {
        if( !function_exists('apache_request_headers') ) {
            $arh = [];
            $rx_http = '/\AHTTP_/';
            foreach($_SERVER as $key => $val) {
                if( preg_match($rx_http, $key) ) {
                    $arh_key = preg_replace($rx_http, '', $key);
                    $rx_matches = explode('_', $arh_key);
                    if( count($rx_matches) > 0 and strlen($arh_key) > 2 ) {
                        foreach($rx_matches as $ak_key => $ak_val) $rx_matches[$ak_key] = ucfirst($ak_val);
                        $arh_key = implode('-', $rx_matches);
                    }
                    $arh[$arh_key] = $val;
                }
            }
            $this->headers = $arh;
        } else {
            $this->headers = apache_request_headers();
        }
    }
    public function listen_value(){
        if($json = json_decode(file_get_contents("php://input"), true)) {
            $this->data = $json;
        } else {
            $this->data = $_REQUEST;
        }
    }
    public function listen_file($key):bool
    {
        if (!isset($_FILES)){
            return false;
        }
        for ($i=0;$i<count($_FILES[$key]['tmp_name']);$i++){
            $this->file[$i]['name']=$_FILES[$key]['name'][$i];
            $this->file[$i]['type']=$_FILES[$key]['type'][$i];
            $this->file[$i]['tmp_name']=$_FILES[$key]['tmp_name'][$i];
            $this->file[$i]['error']=$_FILES[$key]['error'][$i];
            $this->file[$i]['size']=$_FILES[$key]['size'][$i];
        }
        return true;
    }
}
