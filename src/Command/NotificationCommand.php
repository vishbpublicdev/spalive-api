<?php
namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\ORM\TableRegistry;

class NotificationCommand extends Command{
    protected $mailgunKey = null;

    protected function getMailgunKey(): ?string
    {
        if ($this->mailgunKey === null) {
            $this->mailgunKey = env('MAILGUN_API_KEY');
        }
        return $this->mailgunKey;
    }
    
    public function execute(Arguments $args, ConsoleIo $io){
        $this->loadModel('SpaLiveV1.ApiDevice');
        $this->loadModel('SpaLiveV1.DataCertificates');
        $this->ApiApplication = TableRegistry::get('ApiApplication');
        $array_application = $this->ApiApplication->find()->where(['ApiApplication.id' => 1])->first();

        $str_query = "
        SELECT DataConsultation.patient_id, Devices.id as device_id, Devices.token as token, Devices.device as device_type FROM data_certificates DataCertificates
        INNER JOIN data_consultation DataConsultation ON DataConsultation.id = DataCertificates.consultation_id
        INNER JOIN api_devices Devices ON Devices.user_id = DataConsultation.patient_id
        WHERE (DATEDIFF( DataCertificates.date_expiration,  NOW()) BETWEEN 68 AND 72 OR DATEDIFF( DataCertificates.date_expiration,  NOW()) BETWEEN 88 AND 92)
        AND DataConsultation.payment <> ''
        ";
        $find = $this->DataCertificates->getConnection()->execute($str_query)->fetchAll('assoc');

        $deviceAndroid = [];
        $deviceIOS = [];

        foreach($find as $item){
            if($item['device_type'] == "ANDROID"){
                $deviceAndroid[] = $item['token'];
            }else{
                $deviceIOS[] = $item['token'];
            }
        }

        if(!empty($array_application)){
            $data = [];
            $str_message = 'your certificate is about to expire';
            $config = json_decode($array_application, true);
            $array_config = json_decode($array_application->json_config, true);

            // $io->out(json_encode($array_config));    
            if(isset($array_config['android_access_key']) && !empty($array_config['android_access_key'])){
                if(!empty($deviceAndroid)){
                    $this->send_Android($deviceAndroid, $array_config['android_access_key'], $str_message, $data);
                }
            }
            
            define('APP_NAME', $config['appname']);
            define('PATH_IOS_CERT', APP . 'Certificates' . DS . APP_NAME . '.pem');
            if(file_exists(PATH_IOS_CERT)){
                if(!empty($deviceIOS)){
                    $this->send_iOS($deviceIOS, $str_message, $data);
                }
            }
        }

        $str_query_emails = "
        SELECT Users.email  FROM data_certificates DataCertificates
        INNER JOIN data_consultation DataConsultation ON DataConsultation.id = DataCertificates.consultation_id
        INNER JOIN sys_users Users ON Users.id = DataConsultation.patient_id
        WHERE (DATEDIFF( DataCertificates.date_expiration,  NOW()) BETWEEN 68 AND 72 OR DATEDIFF( DataCertificates.date_expiration,  NOW()) BETWEEN 88 AND 92)
        AND DataConsultation.payment <> ''
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
            if($result === FALSE){
                die('Curl failed: ' . curl_error($ch));
            }
            //echo "::{$result}::";

            curl_close($ch);
        }
    }

    private function send_iOS($array_device, $str_message, $data){
        if(empty($array_device)){
            return false;
        }

        $body = array();
        //echo 'Connected to APNS' . PHP_EOL;
        // Create the payload body


        $array_body = array(
            'alert' => $str_message,
            'sound' => 'default',
            'badge' => 1
        );

        if (!empty($data)) {
            $array_body = array_merge($array_body, $data);
        }

        // Encode the payload as JSON
        $payload = json_encode(array('aps' => $array_body));

        foreach ($array_device as /*$reg*/$Device) {
            //$Device = $reg['ApiDevice'];
            // $token = trim($Device->token);
            $token = trim($Device);

            $ctx = stream_context_create();
            stream_context_set_option($ctx, 'ssl', 'local_cert', PATH_IOS_CERT);
            stream_context_set_option($ctx, 'ssl', 'passphrase', Configure::read('API_CONFIG.ios_passphrase'));

            // Open a connection to the APNS server
            if(IOS_DEBUG == 1){
                $fp = stream_socket_client('ssl://gateway.sandbox.push.apple.com:2195', $err, $errstr, 60, STREAM_CLIENT_CONNECT|STREAM_CLIENT_PERSISTENT, $ctx);
            }else{
                $fp = stream_socket_client('ssl://gateway.push.apple.com:2195', $err, $errstr, 60, STREAM_CLIENT_CONNECT|STREAM_CLIENT_PERSISTENT, $ctx);
            }

            if (!$fp) exit("Failed to connect: $err $errstr" . PHP_EOL);

            // Build the binary notification
            $msg = chr(0) . pack('n', 32) . pack('H*', $token) . pack('n', strlen($payload)) . $payload;

            // Send it to the server
            $result = fwrite($fp, $msg, strlen($msg));
            //echo "::{$result}::";
            
            fclose($fp);
        }

        // Close the connection to the server
    }


}