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
use SpaLiveV1\Controller\OtherservicesController;

class ScheduledCommand extends Command{
    protected $mailgunKey = null;

    protected function getMailgunKey(): ?string
    {
        if ($this->mailgunKey === null) {
            $this->mailgunKey = env('MAILGUN_API_KEY');
        }
        return $this->mailgunKey;
    }
    
    public function execute(Arguments $args, ConsoleIo $io){

        $isDev = env('IS_DEV', false);
        
        $this->loadModel('SpaLiveV1.ApiDevice');
        $this->loadModel('SpaLiveV1.DataCertificates');
        $this->loadModel('SpaLiveV1.DataMessages');
        $this->ApiApplication = TableRegistry::get('ApiApplication');
        $array_application = $this->ApiApplication->find()->where(['ApiApplication.id' => 1])->first();

        $str_now = date('Y-m-d H:i:s');

        $str_query = "
        SELECT 
            DataConsultation.patient_id, DataConsultation.assistance_id, DataConsultation.schedule_date, DataConsultation.createdby,
            TIMESTAMPDIFF(MINUTE, '{$str_now}', DataConsultation.schedule_date) as min_diff,
            CONCAT_WS(' ', Patient.name, Patient.lname) as patient,
            CONCAT_WS(' ', Examiner.name, Examiner.lname) as examiner,
            Patient.phone as patient_phone,
            Examiner.phone as examiner_phone,
            # CONCAT_WS(' ', ClinInj.name, ClinInj.lname) as clin_inj,
            (SELECT CONCAT_WS('||', Devices.token, Devices.device) FROM api_devices Devices WHERE Devices.user_id = DataConsultation.patient_id LIMIT 1) as pat_device,
            (SELECT CONCAT_WS('||', Devices.token, Devices.device) FROM api_devices Devices WHERE Devices.user_id = DataConsultation.assistance_id LIMIT 1) as exa_device,
            (SELECT CONCAT_WS('||', Devices.token, Devices.device) FROM api_devices Devices WHERE Devices.user_id = DataConsultation.createdby LIMIT 1) as clin_inj_device
        FROM data_consultation DataConsultation
        INNER JOIN sys_users Examiner ON Examiner.id = DataConsultation.assistance_id
        INNER JOIN sys_users Patient ON Patient.id = DataConsultation.patient_id
        # LEFT JOIN sys_users ClinInj ON ClinInj.id = DataConsultation.createdby
        WHERE TIMESTAMPDIFF(MINUTE, '{$str_now}', DataConsultation.schedule_date) BETWEEN 0 AND 5 
        AND DataConsultation.status = 'INIT' AND DataConsultation.schedule_by > 0 AND DataConsultation.assistance_id > 0
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

            $find = $this->DataCertificates->getConnection()->execute($str_query)->fetchAll('assoc');

            pr($str_now);
            pr($find);

            $data = [];
            $str_msg = 'Reminder - SpaLiveMD: ';
            $str_msg2 = ' exam appointment is in $nmin more minutes. Please arrive a minute earlier to the waiting room.';
            $config = json_decode($array_application, true);
            $array_config = json_decode($array_application->json_config, true);

            $deviceAndroid = [];
            $deviceIOS = [];

            define('APP_NAME', $config['appname']);
            define('PATH_IOS_CERT', APP . 'Certificates' . DS . APP_NAME . '.pem');
            foreach($find as $item){
                $msg = str_replace('$nmin', $item['min_diff'], $str_msg2);

                if(!empty($item['patient'])){
                    $str_message = $str_msg. $item['patient'] . ', your' .$msg;
                    $array_save['message'] = $str_message;
                    $array_save['id_to'] = $item['patient_id'];
                    $c_entity = $this->DataMessages->newEntity($array_save);
                    if(!$c_entity->hasErrors()) 
                        $this->DataMessages->save($c_entity);
                    if(isset($item['pat_device']) && !empty($item['pat_device'])) {
                        $arrVals = explode('||', $item['pat_device']);
                        if($arrVals[1] == "ANDROID"){
                            //$deviceAndroid[] = $arrVals[0];
                            if(isset($array_config['android_access_key']) && !empty($array_config['android_access_key'])){
                                $this->send_Android(array($arrVals[0]), $array_config['android_access_key'], $str_message, $data);
                            }
                        }else{
                            //$deviceIOS[] = $arrVals[0];
                            if(file_exists(PATH_IOS_CERT)){
                                $this->send_iOS(array($arrVals[0]), $str_message, $data);
                            }
                        }
                    }
                    $this->sendSms($isDev, $item['patient_phone'], $str_message);
                }

                if(!empty($item['examiner'])){
                    $str_message = $str_msg. $item['examiner'] . ', your' .$msg; 
                    $array_save['message'] = $str_message;
                    $array_save['id_to'] = $item['assistance_id'];
                    $c_entity = $this->DataMessages->newEntity($array_save);
                    if(!$c_entity->hasErrors()) 
                        $this->DataMessages->save($c_entity);
                    if(isset($item['exa_device']) && !empty($item['exa_device'])) {
                        $arrVals = explode('||', $item['exa_device']);
                        if($arrVals[1] == "ANDROID"){
                            //$deviceAndroid[] = $arrVals[0];
                            if(isset($array_config['android_access_key']) && !empty($array_config['android_access_key'])){
                                $this->send_Android(array($arrVals[0]), $array_config['android_access_key'], $str_message, $data);
                            }
                        }else{
                            // $deviceIOS[] = $arrVals[0];
                            if(file_exists(PATH_IOS_CERT)){
                                $this->send_iOS(array($arrVals[0]), $str_message, $data);
                            }
                        }
                    }
                    $this->sendSms($isDev, $item['examiner_phone'], $str_message);
                    // $this->sendSms($isDev, '5158656757', $str_message);
                }

                if(intval($item['createdby']) != intval($item['patient_id'])){
                    $str_message = $str_msg. $item['patient'] . ', his/her' .$msg; 
                    $array_save['message'] = $str_message;
                    $array_save['id_to'] = $item['createdby'];
                    if(isset($item['clin_inj_device']) && !empty($item['clin_inj_device'])) {
                        $arrVals = explode('||', $item['clin_inj_device']);
                        if($arrVals[1] == "ANDROID"){
                            //$deviceAndroid[] = $arrVals[0];
                            if(isset($array_config['android_access_key']) && !empty($array_config['android_access_key'])){
                                $this->send_Android(array($arrVals[0]), $array_config['android_access_key'], $str_message, $data);
                            }
                        }else{
                            // $deviceIOS[] = $arrVals[0];
                            if(file_exists(PATH_IOS_CERT)){
                                $this->send_iOS(array($arrVals[0]), $str_message, $data);
                            }
                        }
                    }
                    $c_entity = $this->DataMessages->newEntity($array_save);
                    if(!$c_entity->hasErrors()) 
                        $this->DataMessages->save($c_entity);

                    $this->sendSms($isDev, $item['patient_phone'], $str_message);
                }
            }
        }

        // $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');

        // $checkins = $this->DataOtherServicesCheckIn->find()->where(['DataOtherServicesCheckIn.support_id' => 0, 'DataOtherServicesCheckIn.status' => 'CLAIMED', 'DataOtherServicesCheckIn.deleted' => 0])->all();

        /*
        $str_query_emails = "
        SELECT Users.email  FROM data_certificates DataCertificates
        INNER JOIN data_consultation DataConsultation ON DataConsultation.id = DataCertificates.consultation_id
        INNER JOIN sys_users Users ON Users.id = DataConsultation.patient_id
        WHERE (DATEDIFF( DataCertificates.date_expiration,  NOW()) BETWEEN 68 AND 72 OR DATEDIFF( DataCertificates.date_expiration,  NOW()) BETWEEN 88 AND 92)
        AND DataConsultation.status = 'INIT' AND DataConsultation.schedule_by > 0
        ";
        $findEmails = $this->DataCertificates->getConnection()->execute($str_query_emails)->fetchAll('assoc');

        $html_content_creator = 'Hi,
        <br><br>
        this is a notice that you have a certificate about to expire in a few months.';
            
        foreach($findEmails as $item){
            $data=array(
                'from'    => 'SpaLiveMD <noreply@mg.spalivemd.com>',
                'to'      => $item['email'],
                'subject' => 'Notice certificate SpaLiveMD',
                'html'    => $html_content_creator,
            );

            $mailgunKey = $this->getMailgunKey();

            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, 'https://api.mailgun.net/v3/mg.spalivemd.com/messages');
            curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($curl, CURLOPT_USERPWD, 'api:' . $mailgunKey);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($curl, CURLOPT_POST, true); 
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data);

            $result = curl_exec($curl);
            curl_close($curl);
        }
        */

        $Other = new OtherservicesController();
        $Other->reminder_checkin_fivemin();
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