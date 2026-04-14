<?php
declare(strict_types=1);

namespace SpaLiveV1\Controller;
use App\Controller\AppPluginController;
use Cake\Core\Configure;
use Cake\Utility\Security;
use Cake\Utility\Text;
use Cake\Utility\Hash;
require_once(ROOT . DS . 'vendor' . DS  . 'Twilio' . DS . 'autoload.php');
use Twilio\Rest\Client;
use Twilio\Exceptions\TwilioException;
require_once(ROOT . DS . 'vendor' . DS  . 'stripe' . DS . 'init.php');
use Stripe\Stripe;
use \Stripe\Error;

use Spipu\Html2Pdf\Html2Pdf;
use Spipu\Html2Pdf\Exception\Html2PdfException;
use Spipu\Html2Pdf\Exception\ExceptionFormatter;

use SpaLiveV1\Controller\FillersController;
use SpaLiveV1\Controller\MainController;
use Cake\I18n\FrozenTime;
use SpaLiveV1\Controller\Data\ServicesHelper;

class PromosController extends AppPluginController {

    protected $mailgunKey = null;

    protected function getMailgunKey(): ?string
    {
        if ($this->mailgunKey === null) {
            $this->mailgunKey = env('MAILGUN_API_KEY');
        }
        return $this->mailgunKey;
    }

    public function initialize() : void {
        parent::initialize();
		$this->loadModel('SpaLiveV1.AppToken');
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataPatientsPromoDay');
        $this->loadModel('SpaLiveV1.DataPromoDay');
        $this->loadModel('SpaLiveV1.CatTreatmentsPromoDay');

        $this->URL_API = env('URL_API', 'https://api.spalivemd.com/');
        $this->URL_WEB = env('URL_WEB', 'https://app.spalivemd.com/');
        $this->URL_ASSETS = env('URL_ASSETS', 'https://api.spalivemd.com/assets/');
        $this->URL_PANEL = env('URL_PANEL', 'https://panel.spalivemd.com/');

    }

    public function check_token($token){
        if(!empty($token)){
            
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                return array(
                    'session' => false,
                );
            }
            return array(
                'user' => $user,
                'session' => true
            );
        } else {
            return array(
                'session' => false,
            );
        }
    }

    public function get_create_promo_info(){
        $this->loadModel('SpaLiveV1.DataPayment');
        $this->loadModel('SpaLiveV1.DataTrainings');
        $this->loadModel('SpaLiveV1.SysTreatmentsOt');

        $token = get('token',"");
        
        $token_response = $this->check_token($token);

        if(!$token_response['session']){
            $this->message("Invalid token.");
            $this->set('session', false);
            return;
        }

        $treatmets_allowes_provides = array();

        $Summary = new SummaryController();
        $Therapy = new TherapyController();         
        $ServicesHelper = new ServicesHelper($token_response["user"]["user_id"]);

        $status_basic = $ServicesHelper->service_status(
            "BASIC NEUROTOXINS"                
        );

        if($status_basic=="DONE"){
            $treatment = $this->get_treatment("NEUROTOXINS BASIC");

            if(!empty($treatment) && !empty($treatment["id"]) && !empty($treatment["name"])){
                $treatmets_allowes_provides[] = array(
                    'id'    => $treatment["id"],
                    'title' => $treatment["name"]
                );
            }
        } 

        $status_advanced = $ServicesHelper->service_status(
            "ADVANCED NEUROTOXINS"                
        );

        if($status_advanced=="DONE"){
            $treatment = $this->get_treatment("NEUROTOXINS ADVANCED");

            if(!empty($treatment) && !empty($treatment["id"]) && !empty($treatment["name"])){
                $treatmets_allowes_provides[] = array(
                    'id'    => $treatment["id"],
                    'title' => $treatment["name"]
                );
            }
        } 

        $iv_therapy = $Therapy->consult_iv_application($token_response["user"]["user_id"]);   

        if($iv_therapy=="ACCEPTED"){
            $treatment = $this->get_treatment("IV THERAPY");

            if(!empty($treatment) && !empty($treatment["id"]) && !empty($treatment["name"])){
                $treatmets_allowes_provides[] = array(
                    'id'    => $treatment["id"],
                    'title' => $treatment["name"]
                );
            }
        }

        $status = $ServicesHelper->service_status(
            "FILLERS"                
        );

        if($status=="DONE"){
            $treatment = $this->get_treatment("FILLERS");

            if(!empty($treatment) && !empty($treatment["id"]) && !empty($treatment["name"])){
                $treatmets_allowes_provides[] = array(
                    'id'    => $treatment["id"],
                    'title' => $treatment["name"]
                );
            }
        }

        $treatments_ot = $this->SysTreatmentsOt->find()->where(['deleted' => 0, 'id NOT IN' => [1,2,3,999]])->all();

        foreach($treatments_ot as $treatment_ot){

            $status = $ServicesHelper->service_status(
                $treatment_ot->name_key
            );

            if($status=="DONE"){
                $treatment = $this->get_treatment($treatment_ot->name_key);
                if(!empty($treatment) && !empty($treatment["id"]) && !empty($treatment["name"])){
                    $treatmets_allowes_provides[] = array(
                        'id'    => $treatment["id"],
                        'title' => $treatment["name"]
                    );
                }
            }
        }

        $this->set('treatmets_allowes_provides', $treatmets_allowes_provides);
        $this->set('patients', $Summary->find_patients(true));
        $this->set('today', date("Y-m-d H:i:s"));

        $this->success();
        $this->set('session', true);
    }   

    public function get_treatment($category){

        $treatment = $this->CatTreatmentsPromoDay->find()->where(['deleted' => 0, 'category' => $category])->first();

        return $treatment;
    }

    public function save_promo(){
        $token = get('token',"");
        
        $token_response = $this->check_token($token);

        if(!$token_response['session']){
            $this->message("Invalid token.");
            $this->set('session', false);
            return;
        }

        $promo_name = get('promo_name', '');
        $string_treatments_id = get('treatments', '');
        $string_patients = get('patients', '');
        $is_visible_to_public = get('is_visible_to_public', 0);
        $from = get('from', '');
        $to = get('to', '');
        $type = get('type', '');
        $discount = get('discount', '');

        if($promo_name==""){
            $this->message("Please enter a promo name.");
            $this->set('session', false);
            return;
        }

        if($from==""){
            $this->message("Please enter a start date.");
            $this->set('session', false);
            return;
        }

        if($to==""){
            $this->message("Please enter a expiration date.");
            $this->set('session', false);
            return;
        }

        if($type==""){
            $this->message("Please enter a type.");
            $this->set('session', false);
            return;
        }

        if($discount==""){
            $this->message("Please enter a discount.");
            $this->set('session', false);
            return;
        }

        if($string_treatments_id==""){
            $this->message("Please select at least one treatment.");
            $this->set('session', false);
            return;
        }

        if($string_patients==""){
            $this->message("Please select at least one patient.");
            $this->set('session', false);
            return;
        }

        $array_promo = array(
            'user_id' => $token_response["user"]["user_id"],
            'name' => $promo_name,
            'status' => 'ACTIVE',
            'discount_type' => $type,
            'amount' => $discount,
            'start_date' => $from." 00:00:00",
            'end_date' => $to." 23:59:59",
            'deleted' => 0,
            'created' => date("Y-m-d H:i:s"),
            'public' => $is_visible_to_public,
            'categories_id' => $string_treatments_id
        );

        $promo = $this->DataPromoDay->newEntity($array_promo);

        if(!$this->DataPromoDay->save($promo)){
            $this->message("Error saving promo.");
            $this->set('session', false);
            return;
        }else{

            $patients_uid = explode(',', $string_patients);
            $patients_emails = [];
            $ids = "";

            foreach($patients_uid as $uid){
                $patient = $this->SysUsers->find()->select(["SysUsers.id","SysUsers.email"])->where(['uid' => $uid])->first();
                $ids .= $patient->id.",";

                $patients_emails[] = $patient->email;
            }

            $array_patient = array(
                'promo_id' => $promo->id,
                'patients_id' => rtrim($ids, ","),
                'deleted' => 0
            );

            $patient = $this->DataPatientsPromoDay->newEntity($array_patient);

            if(!$this->DataPatientsPromoDay->save($patient)){
                $this->message("Error saving patients.");
                $this->set('session', false);
                return;
            }else{
                //send emails
                $injector = $token_response["user"]["name"]." ".$token_response["user"]["lname"];

                $text_discount = "";

                if($type=="percentage"){
                    $text_discount = $discount."%";
                }else{
                    $text_discount = "$".number_format($discount / 100,2);
                }

                $treatments_categories = $this->get_categories_by_id($string_treatments_id);

                $response = $this->send_promo_email($injector,$patients_emails,$promo_name,$text_discount,$from,$to,$treatments_categories);

                if($response){
                    $this->success();
                    $this->set('session', true);
                }
            }            

        }

    }

    public function send_promo_email($injector,$patients_emails,$promo,$discount,$start_date,$end_date,$string_treatments){

        $html = $promo. '<br><br>Enjoy a '.$discount. ' off on your ';

        $html.= $string_treatments;

        $html.= " treatments.<br><br>";

        $from = strtotime($start_date);
        $to = strtotime($end_date);

        $html.= "From ".date('F j, Y', $from)." to ".date('F j, Y', $to).".<br><br>";

        foreach($patients_emails as $email){
        
            $data = array(
                'from'    => 'MySpaLive <noreply@mg.myspalive.com>',
                'subject' => $injector.' has launched a new promo!',
                'html'    => $html,
                'to'      => $email
            );

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
            curl_setopt($curl, CURLOPT_HEADER, true);
            curl_setopt($curl, CURLOPT_HTTPHEADER, ["Content-Type:multipart/form-data"]);
            
            $result = curl_exec($curl);
            
            curl_close($curl);

        }

        return true;

    }

    public function get_categories_by_id($string_treatments_id){

        $ids = explode(',', $string_treatments_id);

        $string_treatment = "";

        foreach($ids as $id){
            $treatment = $this->CatTreatmentsPromoDay->find()->where(['deleted' => 0, 'id' => $id])->first();
            $string_treatment .= $treatment["category"].", ";
        }

        $string_treatment = rtrim($string_treatment, ", ");

        return $string_treatment;

    }

    public function get_promos_injector(){
        $token = get('token',"");
        
        $token_response = $this->check_token($token);

        if(!$token_response['session']){
            $this->message("Invalid token.");
            $this->set('session', false);
            return;
        }

        $count_all = $this->DataPromoDay->find()->where(['deleted' => 0, 'user_id' => $token_response["user"]["user_id"]])
                                        ->order(['id' => 'DESC'])->all();

        if(count($count_all)==0){
            $this->set('empty_message', "You can now offer discounts to your patients on specific days. This can be done publicly to attract new patients or privately to selected individuals. Once you have given a discount to a chosen patient, they will receive an email notification encouraging them to book a treatment during the promotional period.");
        }else{
            $this->set('empty_message', "");
        }

        $promos_response = [];

        $status = get('status', 'ACTIVE');

        $promos = $this->DataPromoDay->find()->where(['deleted' => 0, 'user_id' => $token_response["user"]["user_id"], 
            'status' => $status])->order(['id' => 'DESC'])->all();

        foreach($promos as $promo){

            $patients = "";

            if($promo->public==0){
                $patients = $this->get_patients_by_promo($promo->id);
            }else{
                $patients = "Everyone";
            }

            $text_discount = "";

            if($promo->discount_type=="percentage"){
                $text_discount = $promo->amount."%";
            }else{
                $text_discount = "$".number_format($promo->amount / 100,2);
            }

            $treatments_categories = $this->get_categories_by_id($promo->categories_id);

            $promos_response[] = array(
                'id' => $promo->id,
                'name' => $promo->name,
                'discount' => $text_discount,
                'start_date' => $promo->start_date->format('m/d/Y'),
                'end_date' => $promo->end_date->format('m/d/Y'),
                'public' => $promo->public,
                'patients' => $patients,
                'status' => $promo->status,
                'treatments' => $treatments_categories,
            );
        }

        $this->set('promos_day', $promos_response);
        $this->set('session', true);
        $this->success();

    }

    public function get_patients_by_promo($promo_id){

        $ent_patients = $this->DataPatientsPromoDay->find()->where(['deleted' => 0, 'promo_id' => $promo_id])->first();

        if(empty($ent_patients)){
            return "";
        }

        $patients_ids = explode(',', $ent_patients->patients_id);

        $string_patients = "";

        foreach($patients_ids as $id){
            $patient = $this->SysUsers->find()->select(["SysUsers.id","SysUsers.name","SysUsers.lname"])->where(['id' => $id])->first();

            $string_patients .= $patient->name." ".$patient->lname.", ";
        }

        return rtrim($string_patients, ", ");
    }

    public function change_status_promo(){
        $token = get('token',"");
        
        $token_response = $this->check_token($token);

        if(!$token_response['session']){
            $this->message("Invalid token.");
            $this->set('session', false);
            return;
        }

        $promo_id = get('promo_id', '');
        $status = get('status', '');

        if($promo_id==""){
            $this->message("Please select a promo.");
            $this->set('session', false);
            return;
        }

        if($status==""){
            $this->message("Please select a status.");
            $this->set('session', false);
            return;
        }

        $promo = $this->DataPromoDay->find()->where(['user_id' => $token_response["user"]["user_id"], 'deleted' => 0, 'id' => $promo_id])->first();

        if(!$promo){
            $this->message("Promo not found.");
            $this->set('session', false);
            return;
        }

        $promo->status = $status;

        if(!$this->DataPromoDay->save($promo)){
            $this->message("Error saving status promo.");
            $this->set('session', false);
            return;
        }else{
            $this->success();
            $this->set('session', true);
        }
    }

    public function test_crono_job(){
        $this->loadModel('SpaLiveV1.DataPromoDay');

        $now = date('Y-m-d H:i:s');

        $promos = $this->DataPromoDay->find()
                                    ->where(['deleted' => 0, 'status !=' => 'EXPIRED',
                                            '(DATE_FORMAT(DataPromoDay.end_date, "%Y-%m-%d %H:%S:59") < "' . $now . '")'])->all();

        foreach($promos as $promo){

            $promo->status = "EXPIRED";

            if(!$this->DataPromoDay->save($promo)){
                $this->message("Error saving status promo.");
                return;
            }
            
        }

        $this->success();

    }

    public function change_category_labels($categories){
            
        $categories = explode(',', $categories);

        $string_categories = "";

        foreach($categories as $category){

            if($category=="NEUROTOXINS BASIC"){
                if(!str_contains($string_categories, "Neurotoxins")){
                    $string_categories .= "Neurotoxins, ";
                }
            }else if($category=="NEUROTOXINS ADVANCED"){
                if(!str_contains($string_categories, "Neurotoxins")){
                    $string_categories .= "Neurotoxins, ";
                }
            }else if($category=="IV THERAPY"){
                $string_categories .= "IV, "; 
            }else if($category=="FILLERS"){
                $string_categories .= "Fillers, "; 
            }
            
        }

        return rtrim($string_categories, ", ");
    }

    public function get_discount_for_treatments($promo_code, $ent_treatment){
        $this->loadModel('SpaLiveV1.DataPromoDay');
        $has_discount = false;
        $discount = 0;
        $discount_text = "";
        $discount_type = "";
        $treatments_categories = "";
        $promo_name = "";
        $now = date('Y-m-d H:i:s');

        $_join = [
            'Patients' => ['table' => 'data_patients_promo_day', 'type' => 'INNER', 'conditions' => 'DataPromoDay.id = Patients.promo_id']
        ];

        $_fields = ['DataPromoDay.id','DataPromoDay.name', 'Patients.patients_id', 'DataPromoDay.amount', 'DataPromoDay.discount_type', 'DataPromoDay.public', 
                        'DataPromoDay.categories_id'];
        $id =0;
        if(!empty($promo_code)&&$promo_code!=""){
            //si ingresan el código

            $_where = ['DataPromoDay.name' => $promo_code,'DataPromoDay.deleted' => 0,
                        'DataPromoDay.status' => "ACTIVE", 'Patients.deleted' => 0, '(DATE_FORMAT(DataPromoDay.end_date, "%Y-%m-%d %H:%i:%s") > "' . $now . '")'];

            $ent_promo = $this->DataPromoDay->find()->select($_fields)->join($_join)->where($_where)->first();

            if(!empty($ent_promo)){
                $id = $ent_promo->id;
                if($ent_promo->public == 1){
                    $has_discount = true;
                    $discount = $ent_promo->amount;
                    $discount_type = $ent_promo->discount_type;
                    $promo_name = $ent_promo->name;

                    $treatments_categories = $this->get_categories_by_id($ent_promo->categories_id);
                }else{

                    if (strpos($ent_promo["Patients"]["patients_id"], strval($ent_treatment->patient_id)) !== false) {
                        $has_discount = true;
                        $discount = $ent_promo->amount;
                        $discount_type = $ent_promo->discount_type;
                        $promo_name = $ent_promo->name;

                        $treatments_categories = $this->get_categories_by_id($ent_promo->categories_id);

                    }
                }
            }
        }else{
            //si no ingresan el código
            $_where = ['DataPromoDay.deleted' => 0, 'DataPromoDay.status' => "ACTIVE", 'DataPromoDay.user_id' => $ent_treatment->assistance_id,
                       'DataPromoDay.public' => 1, '(DATE_FORMAT(DataPromoDay.end_date, "%Y-%m-%d %H:%i:%s") > "' . $now . '")'];

            $ent_promo = $this->DataPromoDay->find()->select($_fields)->join($_join)->where($_where)->first();
            
            if(!empty($ent_promo)){
                $id = $ent_promo->id;
                $has_discount = true;
                $discount = $ent_promo->amount;
                $discount_type = $ent_promo->discount_type;
                $promo_name = $ent_promo->name;

                $treatments_categories = $this->get_categories_by_id($ent_promo->categories_id);
            }else{
                
                //obtener todas las promociones activas del inyector, para buscar si en alguna esta el id del paciente
                $_where = ['DataPromoDay.deleted' => 0, 'DataPromoDay.status' => "ACTIVE", 'DataPromoDay.user_id' => $ent_treatment->assistance_id,
                        'Patients.deleted' => 0, '(DATE_FORMAT(DataPromoDay.end_date, "%Y-%m-%d %H:%i:%s") > "' . $now . '")'];

                $all_promos = $this->DataPromoDay->find()->select($_fields)->join($_join)->where($_where)->all();

                foreach($all_promos as $p){

                    if (strpos($p["Patients"]["patients_id"], strval($ent_treatment->patient_id)) !== false) {
                        $has_discount = true;
                        $discount = $p->amount;
                        $discount_type = $p->discount_type;
                        $promo_name = $p->name;

                        $treatments_categories = $this->get_categories_by_id($p->categories_id);

                        $ent_promo = $p;
                        break;

                    }
                }
            }
                
        }

        if($has_discount){
            if($discount_type=="percentage"){
                $discount_text = $ent_promo->amount."%";
            }else{
                $discount_text = "$".number_format($ent_promo->amount / 100,2);
            }
        }

        $response = array(
            'id' => $id,
            'has_discount' => $has_discount,
            'discount' => $discount,
            'discount_type' => $discount_type,
            'discount_text' => $discount_text,
            'treatments_categories' => $treatments_categories,
            'promo_name' => $promo_name,
        );
        $this->log(__LINE__ . ' ' . __FILE__ .' '. json_encode($response));
        return $response;

    }

}