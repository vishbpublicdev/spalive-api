<?php
namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\ORM\TableRegistry;
use Cake\Core\Configure;
use Cake\Utility\Text;
require_once(ROOT . DS . 'vendor' . DS  . 'Twilio' . DS . 'autoload.php');
use Twilio\Rest\Client; 
use Twilio\Exceptions\TwilioException; 
require_once(ROOT . DS . 'vendor' . DS  . 'stripe' . DS . 'init.php');
use Stripe\Stripe;
use \Stripe\Error;

use SpaLiveV1\Controller\MainController;
use SpaLiveV1\Controller\PaymentsController;

class EmailCommand extends Command{

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
        $this->email48hrsafterTratment();
       
 
    }

    public function email48hrsafterTratment(){
        $this->loadModel('SpaLiveV1.DataTreatment');
        //SELECT id,patient_id,`schedule_date`,`modified`,`status`,DATE_FORMAT(modified,'%Y-%m-%d') AS mod2 , DATE_FORMAT(now() - interval 2 day,'%Y-%m-%d') AS minus2 FROM `data_treatment` WHERE `status` = "DONE" AND DATE_FORMAT(modified,'%Y-%m-%d') = DATE_FORMAT(now() - interval 2 day,'%Y-%m-%d')   ORDER BY `data_treatment`.`id` DESC;                        
        //$str_query ='SELECT data_treatment.id,patient_id,schedule_date, p.email pemail, p.name pname, p.lname plname, inj.name injname, inj.lname injlname, treatments, GROUP_CONCAT( ct.name ) treatments  FROM data_treatment left join sys_users p on p.id = patient_id left join sys_users inj on inj.id = assistance_id inner join cat_treatments ct on FIND_IN_SET(ct.id, treatments)        WHERE status = "DONE" AND DATE_FORMAT(schedule_date,"%Y-%m-%d") = DATE_FORMAT(now() - interval 2 day,"%Y-%m-%d") group by data_treatment.id';
        $str_query ='SELECT data_treatment.id,patient_id,schedule_date, p.email pemail, p.name pname, p.lname plname, inj.name injname, inj.lname injlname, treatments, GROUP_CONCAT( ct.name ) treatments, TIMESTAMPDIFF(HOUR, schedule_date, now()) as difference  FROM data_treatment left join sys_users p on p.id = patient_id left join sys_users inj on inj.id = assistance_id inner join cat_treatments ct on FIND_IN_SET(ct.id, treatments) WHERE status = "DONE"  group by data_treatment.id  having  difference between  48 and 71 ';        
        $users = $this->DataTreatment->getConnection()->execute($str_query)->fetchAll('assoc');           

        //print_r($users);
        for ($i=0; $i < count($users); $i++) { 
            //if(!empty($ent_user)) {
                $pname = $users[$i]['pname']; 
                $plname = $users[$i]['plname']; 
                $injname = $users[$i]['injname']; 
                $injlname = $users[$i]['injlname']; 
                $treatments = $users[$i]['treatments']; 
                $pemail = $users[$i]['pemail'];
                $html_content = 'Dear '. $pname .' '. $plname. '<br><br>' .
                   'I hope this email finds you well. We wanted to follow up with you regarding your recent treatment with ' .$injname .' '. $injlname .' who '. $treatments.'<br><br>'.
                   'We understand that starting a new treatment can be a big decision, and we want to ensure that you are completely satisfied with your experience. If you have any questions or concerns about the treatment, please do not hesitate to reach out to us directly <span style="color:blue">Patientrelations@myspalive.com</span> Our team of Certified professionals and Patient Relation representatives are available to assist you in any way possible.<br><br>'.
                   'Additionally, we would love to hear about your experience with us. Your feedback is essential in helping us improve and provide the best possible care for our patients.<br><br>'.
                   'Thank you for choosing MySpaLive. We appreciate your trust in us and look forward to serving you in the future.<br><br>'.
                   'Go to our app to schedule your next appointment.<br><br><br>'.
                   'Best regards<br>
                   Johanah Delwarte<br>
                   Patient Relation coordinator with MySpaLive<br>'
                   ;



                

                $data = array(
                    'from'    => 'MySpaLive <noreply@mg.myspalive.com>',
                    'to'    => $pemail,                    
                    'subject' => 'Post treatment',
                    'html'    => $html_content,
                );

                print_r($data);

                $mailgunKey = $this->getMailgunKey();

               $curl = curl_init();
                curl_setopt($curl, CURLOPT_URL, 'https://api.mailgun.net/v3/mg.myspalive.com/messages');
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

            //}
        }
        
    }



}