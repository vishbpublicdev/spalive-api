<?php
declare(strict_types=1);

namespace App\Controller;

use Cake\Controller\Controller;
use Cake\ORM\TableRegistry;
use Cake\Event\EventInterface;
use Cake\Core\Configure;
use Cake\Mailer\Mailer;

class AppPluginController extends Controller
{
    private $messages = [];
    private $success = false;
    private $_set = [];

    public function initialize(): void
    {
        parent::initialize();

        $this->loadComponent('RequestHandler');
        $this->loadComponent('SpaLiveV1.Files', Configure::read('App.files_settings'));
    }

    public function success($_success = true)
    {
        $this->success = $_success;
    }

    public function message($_message = null) {
        if(is_array($_message)){
            $this->messages = array_merge($this->messages, $_message);
        }else{
            $this->messages[] = $_message;
        }
    }

    public function set($key, $data = []) {
        $this->_set[$key] = $data;
    }

    public function getParams($key, $default = null) {
        return isset($this->_set[$key]) ? $this->_set[$key] : $default;
    }

    public function output() {
        // $ver = get('version', '');
        // $key = get('key', '');
        // $ver = str_replace('version ', '', $ver);
        // $b_ver = ($key != '225sadfgasd123fgkhijjdsadfg16578g12gg3gh' && $key != '5hf3gdhgfi3ugjbni3ifgisdfgn45h.sdfg3hhuh'
        //      && (empty($ver) || (!empty($ver) && $ver != '1.15' && $ver != '1.16' && $ver != '1.17' && $ver != '1.18' && $ver != '1.19')));
        // if($b_ver){
        //     $this->messages[] = 'There is a new version avalaible, update your app to continue.';
        // }

        $result = [
            'success' => $this->success ,
            'min_ver_ios' => Configure::read('App.min_ver_ios'),
            'min_ver_android' => Configure::read('App.min_ver_android'),
        ];

        if(!empty($this->messages)){
            $result['messages'] = $this->messages;
        }

        if(!empty($this->_set)){
            $result = array_merge($this->_set, $result);
        }
        
        return $result;
    }


    public function send($str_message, $data = array(), $array_devices_ids = array()){
        $this->loadModel('SpaLiveV1.ApiDevice');
        $this->ApiApplication = TableRegistry::get('ApiApplication');
        
        $app_id = 1;
        $notify_developers = 0;
        if(defined('APP_ID') && APP_ID !== '__UNINITIALIZED__'){
            $app_id = APP_ID;
        }

        if(defined('NOTIFY_DEVELOPERS') && NOTIFY_DEVELOPERS !== '__UNINITIALIZED__'){
            $notify_developers = NOTIFY_DEVELOPERS;
        }   

        $array_application = $this->ApiApplication->find()->where(['ApiApplication.id' => $app_id])->first();

        // if(!empty($array_application)){
        //     $array_config = json_decode($array_application->json_config, true);
        //     //$str_message = get('message', '');
        //     //$str_json_data = get('data', '{}');
        //     //$data = json_decode($str_json_data, true);

        //     if (!defined('NOTIFY_DEVELOPERS')) define('NOTIFY_DEVELOPERS', isset($array_config['notify_developer'])? $array_config['notify_developer'] : 0);
        //     if (!defined('IOS_DEBUG')) define('IOS_DEBUG', isset($array_config['ios_debug'])? $array_config['ios_debug'] : 0);

        //     $array_conditions = [
        //         'ApiDevice.application_id' => APP_ID
        //     ];

        //     if(empty($array_devices_ids)){
        //        return; 
        //     }
        //     $array_conditions['ApiDevice.id IN'] = $array_devices_ids;

        //     if(NOTIFY_DEVELOPERS == 1){
        //         $array_conditions['ApiDevice.developer'] = 1;
        //     }

        //     if(isset($array_config['android_access_key']) && !empty($array_config['android_access_key'])){
        //         $array_conditions['ApiDevice.device'] = 'ANDROID';
                
        //         $array_device = $this->ApiDevice->find()->where($array_conditions)->toArray();
                
        //         if(!empty($array_device)){
        //             $this->send_Android($array_device, $array_config['android_access_key'], $str_message, $data);
        //         }

        //     }

        //     if(!defined('PATH_IOS_CERT'))define('PATH_IOS_CERT', APP . 'Certificates' . DS . APP_NAME . '.pem');

        //     if(file_exists(PATH_IOS_CERT)){
        //         $array_conditions['ApiDevice.device'] = 'IOS';

        //         $array_device = $this->ApiDevice->find()->where($array_conditions)->toArray();

        //         if(!empty($array_device)){
        //             $this->send_iOS($array_device, $str_message, $data, $array_config['ios_passphrase']);
        //         }
                
        //     } else {
        //         echo "not pem found"; exit;
        //     }

        //     $this->success();
        // }   

        if(!empty($array_devices_ids)){
            $array_config = json_decode($array_application->json_config, true);

            if (!defined('NOTIFY_DEVELOPERS')) define('NOTIFY_DEVELOPERS', isset($array_config['notify_developer'])? $array_config['notify_developer'] : 0);            

            $array_conditions = [
                'ApiDevice.application_id' => $app_id
            ];

            if(empty($array_devices_ids)){
               return; 
            }

            $array_conditions['ApiDevice.id IN'] = $array_devices_ids;

            if($notify_developers == 1){
                $array_conditions['ApiDevice.developer'] = 1;
            }

            $array_device = $this->ApiDevice->find()
                                            ->select(['ApiDevice.token'])
                                            ->where($array_conditions)
                                            ->toArray();

            $array_devices_tokens = array();
            foreach ($array_device as $key => $value) {
                $array_devices_tokens[] = $value->token;
            }

            if(!empty($array_device)){
                $this->send_fcm_notification($array_devices_tokens, 
                                             'MySpaLive', 
                                             $str_message);
            }            
        }

        $this->success();
    }

    protected function send_Android($array_device, $android_access_key, $str_message, $data){

        
        $notification = array();
        $notification['message'] = $str_message;

        if (!empty($data)) {
            $notification = array_merge($notification, $data);
        }

        foreach ($array_device as /*$reg*/ $Device) {
            //$Device = $reg['ApiDevice'];
            $token = trim($Device->token);

            $fields = array(
                'to' => $token,
                'data' => $notification
            );

            $url = 'https://fcm.googleapis.com/fcm/send';

            //$firebase_api = Configure::read('API_CONFIG.android_access_key');

            $headers = array(
                'Authorization: key=' . $android_access_key,//$firebase_api,
                'Content-Type: application/json'
            );

            // Open connection
            $ch = curl_init();

            // Set the url, number of POST vars, POST data
            curl_setopt($ch, CURLOPT_URL, $url);

            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            // Disabling SSL Certificate support temporarily
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));

            // Execute post
            curl_exec($ch);

            // if($result === FALSE){

            //     //die('Curl failed: ' . curl_error($ch));

            // }
            

            curl_close($ch);
            
        }
    }

    private function send_iOS($array_device, $str_message, $data, $ios_passphrase){
        if(empty($array_device)){
            return false;
        }

       
        $body = array();
        // Create the payload body


        $body['aps'] = array(
            'alert' => $str_message,
            'message' => $str_message,
            'sound' => 'default',
            'badge' => 1
        );

        if (!empty($data)) {
            $body = array_merge($data,$body);
        }

        // Encode the payload as JSON
        $payload = json_encode($body);
        
        foreach ($array_device as $Device) {


            //curl -v --header "apns-topic: com.advante.SpaLiveMD" --header "apns-push-type: alert" --cert "aps-2.cer" --cert-type DER --key "PushKey.pem" --key-type PEM --data '{"aps":{"alert":"test"}}' --http2  https://api.push.apple.com/3/device/402226284ec39f60bc94ed780d1eed10bcd4a1849c4c959ec3d72e1267554c2a

            
            $device_token = trim($Device->token);
            $pem_file       = PATH_IOS_CERT;
            $pem_secret     = Configure::read('API_CONFIG.ios_passphrase');
            $apns_topic     = 'com.advante.SpaLiveMD';


            $sample_alert = '
             {
                "aps":{
                    "alert":"' . $str_message . '",
                    "sound":"default"
                }
             }';
            // $url = "https://api.development.push.apple.com/3/device/$device_token";
            $url = "https://api.push.apple.com/3/device/$device_token";

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $sample_alert);
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array("apns-topic: $apns_topic"));
            curl_setopt($ch, CURLOPT_SSLCERT, $pem_file);
            curl_setopt($ch, CURLOPT_SSLCERTPASSWD, $pem_secret);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER,true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 1);
            curl_setopt($ch, CURLOPT_NOSIGNAL, 1);


            curl_exec($ch);

            // curl_getinfo($ch, CURLINFO_HTTP_CODE);

            //On successful response you should get true in the response and a status code of 200
            //A list of responses and status codes is available at 
            //https://developer.apple.com/library/ios/documentation/NetworkingInternet/Conceptual/RemoteNotificationsPG/Chapters/TheNotificationPayload.html#//apple_ref/doc/uid/TP40008194-CH107-SW1

            //var_dump($response);
            //var_dump($httpcode);

            /*

           $token = trim($Device->token);
           
           $ctx = stream_context_create();
            stream_context_set_option($ctx, 'ssl', 'local_cert', PATH_IOS_CERT);
            stream_context_set_option($ctx, 'ssl', 'passphrase', Configure::read('API_CONFIG.ios_passphrase'));

            
            // Open a connection to the APNS server
            // if(IOS_DEBUG == 1){
            if(1 == 1){
                $fp = stream_socket_client('ssl://gateway.sandbox.push.apple.com:2195', $err, $errstr, 60, STREAM_CLIENT_CONNECT|STREAM_CLIENT_PERSISTENT, $ctx);
            }else{
                $fp = stream_socket_client('ssl://gateway.push.apple.com:2195', $err, $errstr, 60, STREAM_CLIENT_CONNECT|STREAM_CLIENT_PERSISTENT, $ctx);
            }

            if (!$fp) exit("Failed to connect: $err $errstr" . PHP_EOL);

            // Build the binary notification
            $msg = chr(0) . pack('n', 32) . pack('H*', $token) . pack('n', strlen($payload)) . $payload; // Build the binary notification
            $result = fwrite($fp, $msg, strlen($msg)); // Send it to the server
            if (!$result)
                echo 'Message not delivered' . PHP_EOL;
            else
                echo 'Message successfully delivered' . PHP_EOL;
            fclose($fp); // Close the connection to the server
            */            
        }

        

        // Close the connection to the server
    }

    public function send_email($str_email, $subject, $name, $array_vars){
        $email = new Mailer('default');

        try {
            
            $email->setEmailFormat('html');
            $email->setTo($str_email);
            $email->viewBuilder()->setTemplate('default');
            $email->setSubject($subject);
            $email->setViewVars($array_vars);
            // $email->setAttachments([
            //     'logo.png' => [
            //         'file' => WWW_ROOT . 'img' . DS . 'logo_imagen.png', //getenv('APP_LOGO'),
            //         'mimetype' => 'image/png',
            //         'contentId' => 'logo-bdk'
            //     ]
            // ]);
            $email->deliver();
            return true;
        } catch (Exception $e) {
            echo 'Exception : ',  $e->getMessage(), "\n";
            return false;
        }
    }

    public function send_fcm_notification($device_tokens, 
                                          $title, 
                                          $body, 
                                          $image = '',
                                          $click_action = '',
                                          $data = array(), 
                                          $time_to_live = 3600){
        $url='https://fcm.googleapis.com/fcm/send';            
        $apiKey='AAAAA9BBleM:APA91bHVd7eNugYLQjsjAamO7NSnPPc9y8AHsi1j9jRi0ApdVmA8DN27XsObPkezG3akcotIFg0x_fpWnJ-zcTa11s5IdqnMG9NZt3NLStZVmXgSFOYsoRr3QDqDGx5Jz1VjuyEQ34ZB';
        $headers = array(
            'Authorization:key='.$apiKey,
            'Content-Type:application/json'
        );
        $notifData = [
            'title'=>$title,
            'body'=>$body,                        
        ];

        if(!empty($image)){
            $notifData['image'] = $image;
        }

        if(!empty($click_action)){
            $notifData['click_action'] = $click_action;
        }

        $notifBody = [
            'notification' => $notifData,
            // 'data' => $data,
            'time_to_live' => $time_to_live,
            'registration_ids' => $device_tokens,
        ];

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);         
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($notifBody));
        curl_setopt($curl, CURLOPT_TIMEOUT, 1);
        curl_setopt($curl, CURLOPT_NOSIGNAL, 1);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);            
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);

        $result = curl_exec($curl);

        curl_close($curl);        
    }
}
