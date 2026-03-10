<?php
namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\ORM\TableRegistry;
use Cake\Core\Configure;
require_once(ROOT . DS . 'vendor' . DS  . 'Twilio' . DS . 'autoload.php');
use Twilio\Rest\Client; 
use Twilio\Exceptions\TwilioException; 

class AutocancelCommand extends Command{
    
    public function execute(Arguments $args, ConsoleIo $io){

        $isDev = env('IS_DEV', false);
        
        $this->loadModel('SpaLiveV1.ApiDevice');
        $this->loadModel('SpaLiveV1.DataTreatment');
        $this->loadModel('SpaLiveV1.DataMessages');
        $this->ApiApplication = TableRegistry::get('ApiApplication');
        $array_application = $this->ApiApplication->find()->where(['ApiApplication.id' => 1])->first();

        $str_now = date('Y-m-d H:i:s');

        $str_query = "
            SELECT TIMESTAMPDIFF(HOUR,DT.created,'{$str_now}') past_time, DT.created, DT.id, DT.patient_id, DT.assistance_id,
            Patient.phone as patient_phone,
            Injector.phone as injector_phone,
            (SELECT CONCAT_WS('||', Devices.token, Devices.device) FROM api_devices Devices WHERE Devices.user_id = DT.patient_id LIMIT 1) as pat_device,
            (SELECT CONCAT_WS('||', Devices.token, Devices.device) FROM api_devices Devices WHERE Devices.user_id = DT.assistance_id LIMIT 1) as exa_device

            FROM data_treatment DT
            INNER JOIN sys_users Injector ON Injector.id = DT.assistance_id
            INNER JOIN sys_users Patient ON Patient.id = DT.patient_id
            WHERE DT.status = 'INIT' AND DT.deleted = 0 
            HAVING past_time >= 4
        ";

        if(!empty($array_application)){
            $array_save = array(
                'type' => 'NOTIFICATION',
                'id_from' => 0,
                // 'id_to' => $user_id,
                // 'message' => $conf_body_push,
                'extra' => '',
                'deleted' => 0,
                'readed' => 0,
                'created' => date('Y-m-d H:i:s'),
            );

            $find = $this->DataTreatment->getConnection()->execute($str_query)->fetchAll('assoc');

            $data = [];
            $str_msg = 'The Certified Injector you requested an appointment with didn\'t confirm. Please request another one with the same or another Certified Injector.';
            
            $config = json_decode($array_application, true);
            $array_config = json_decode($array_application->json_config, true);

            $deviceAndroid = [];
            $deviceIOS = [];

            define('APP_NAME', $config['appname']);
            define('PATH_IOS_CERT', APP . 'Certificates' . DS . APP_NAME . '.pem');
            foreach($find as $item){

                $str_update = "UPDATE data_treatment DT SET DT.status = 'CANCEL' WHERE DT.id = {$item['id']}";
                $this->DataTreatment->getConnection()->execute($str_update)->fetchAll('assoc');
                
                $array_save['message'] = $str_msg;
                $array_save['id_to'] = $item['patient_id'];
                $c_entity = $this->DataMessages->newEntity($array_save);
                if(!$c_entity->hasErrors()) 
                    $this->DataMessages->save($c_entity);
                if(isset($item['pat_device']) && !empty($item['pat_device'])) {
                    $arrVals = explode('||', $item['pat_device']);
                    if($arrVals[1] == "ANDROID"){
                        if(isset($array_config['android_access_key']) && !empty($array_config['android_access_key'])){
                            $this->send_Android(array($arrVals[0]), $array_config['android_access_key'], $str_msg, $data);
                        }
                    }else{
                        if(file_exists(PATH_IOS_CERT)){
                            $this->send_iOS(array($arrVals[0]), $str_msg, $data);
                        }
                    }
                }
                $this->sendSms($isDev, $item['patient_phone'], $str_msg);
                
            }
        }

    }



    protected function send_Android($array_device, $android_access_key, $str_message, $data){
        $notification = array();
        $notification['message'] = $str_message;

        if (!empty($data)) {
            $notification = array_merge($notification, $data);
        }

        foreach ($array_device as /*$reg*/ $Device) {
            //$Device = $reg['ApiDevice'];
            $token = trim($Device);

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
            $result = curl_exec($ch);
            // if($result === FALSE){
            //     die('Curl failed: ' . curl_error($ch));
            // }
            //echo "::{$result}::";

            curl_close($ch);
        }
    }

    private function send_iOS($array_device, $str_message, $data){
        if(empty($array_device)){
            return false;
        }

       pr('array_device');
       pr($array_device);
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

            $token = trim($Device);
           
            
            $device_token = trim($token);
            $pem_file       = PATH_IOS_CERT;
            $pem_secret     = 'c0ntr01';//Configure::read('API_CONFIG.ios_passphrase');
            $apns_topic     = 'com.advante.SpaLiveMD';

            $url = "https://api.push.apple.com/3/device/$device_token";

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array("apns-topic: $apns_topic"));
            curl_setopt($ch, CURLOPT_SSLCERT, $pem_file);
            curl_setopt($ch, CURLOPT_SSLCERTPASSWD, $pem_secret);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER,true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 1);
            curl_setopt($ch, CURLOPT_NOSIGNAL, 1);

            $result = curl_exec($ch);

            if (curl_errno($ch)) {
                $error_msg = curl_error($ch);
                pr($error_msg);
            }

            curl_close($ch);

        }

    }

    private function sendSms($isDev, $phone_number, $str_message){
        if($isDev !== true || $isDev !== "true" ){
            $phone_number = '+1' . $phone_number;
            try {           
                $sid    = env('TWILIO_ACCOUNT_SID'); 
                $token  = env('TWILIO_AUTH_TOKEN'); 
                $twilio = new Client($sid, $token); 
                 
                $message = $twilio->messages 
                ->create($phone_number, // to 
                    array(  
                        "messagingServiceSid" => "MG65978a5932f4ba9dd465e05d7b22195e",      
                        "body" => $str_message 
                    ) 
                ); 
            } catch (TwilioException $e) {}
        }
    }


}