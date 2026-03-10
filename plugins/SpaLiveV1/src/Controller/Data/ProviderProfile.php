<?php
declare(strict_types=1);

namespace SpaLiveV1\Controller\Data;

#region IMPORTS
use App\Controller\AppPluginController;
use Cake\Core\Configure;
use Cake\Utility\Security;
use Cake\Utility\Text;
use CURLFile;
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
use App\Command\RemindersCommand;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use \PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

use SpaLiveV1\Controller\SubscriptionController;
use SpaLiveV1\Controller\CourseController;
use SpaLiveV1\Controller\ProviderProfileController;
use SpaLiveV1\Controller\FillersController;
use SpaLiveV1\Controller\Data\ProfileAttributes;


#endregion

class ProviderProfile extends AppPluginController {

#region ATTRIBUTES
public $uid_provider;
public $id_provider;
public $patient_id;
//Data From User
public $name;
public $state_id;
public $lname;
public $mname;
public $gender;
public $profile_image;
public $description;
public $provider_url;
public $featured;
public $images;
//Data From Provider
public $services;
public $categorized_services;
public $body_areas;
public $tags;
public $certifications;
public $categorized_certifications;
public $schools_certifications;
public $treatment_options;
public $certification_medical;
public $has_medical;
public $provide_fillers;
//Other Stuff
public $rating;
public $reviews;
public $before_after;
public $instagram_profile;
public $promos;
public $subscription_status;
#endregion

#region FUNCTIONS
private function __construct($value, $fill, $patient_id = 0) {
    $this->uid_provider = $value;
    $this->patient_id   = $patient_id;
    if($fill){
        // This must be the first function to be called - it will set the id_provider - set_id_provider();
        $this->set_profile_data(); #DONE
        $this->check_if_exist(); #DONE

        $this->set_rating(); #DONE      
        $this->set_certifications();        
        $this->set_schools_certifications(); #DONE    
        $this->set_other_treatments_certifications(); #DONE
        $this->set_categorized_certificates(); #DONE
        $this->set_services(); #DONE    
        $this->set_categorized_services(); #DONE
        $this->set_body_areas(); #DONE
        $this->set_tags(); #DONE
        $this->set_featured(); #DONE
        $this->set_images(); #DONE
        $this->set_treatment_options(); #DONE
        $this->set_provide_fillers(); #DONE

        $this->set_reviews(); #DONE             
        $this->set_before_after(); #DONE 
        $this->set_instagram_profile(); #DONE
        $this->set_promos();
        $this->set_certification_medical();
        $this->set_subscriptions_status();
    }
}

public function set_profile_data(){
    $this->loadModel('SpaLiveV1.SysUsers'); 
    $ent_user = $this->SysUsers->find()->where(['SysUsers.uid' => $this->uid_provider])->first();
    if(!empty($ent_user)){
        $this->id_provider = $ent_user->id;
        $this->state_id = $ent_user->state;
        $this->name  = trim($ent_user->name);
        $this->lname = trim($ent_user->lname);
        $this->mname = trim($ent_user->mname);
        $this->gender = $ent_user->gender;
        $this->profile_image = $ent_user->photo_id;  
        $this->description = $ent_user->description;  
    }   
}

public function check_if_exist(){
    $this->loadModel('SpaLiveV1.DataProviderProfile'); 
    $ent_provider = $this->DataProviderProfile->find()->where(['DataProviderProfile.injector_id' => $this->id_provider])->first();                
    $ent_user     = $this->SysUsers->find()->where(['SysUsers.id' => $this->id_provider])->first();
    if(empty($ent_provider)){
        $arr_save = array(
            "injector_id" => $this->id_provider,
        );            
        $ppc = new ProviderProfileController();
        
        if(!$ppc->check_url_exist(str_replace(' ', '',$this->name . $this->lname))){
            $ent_user->provider_url = str_replace(' ','',$this->name . $this->lname);
        } else{
            if(!$ppc->check_url_exist(str_replace(' ','',$this->name . $this->mname . $this->lname))){
                $ent_user->provider_url = str_replace(' ','',$this->name . $this->mname . $this->lname);
            } else{
                if(!$ppc->check_url_exist(str_replace(' ','',$this->name . '.' . $this->lname))){
                    $ent_user->provider_url = str_replace(' ','',$this->name . '.' . $this->lname);
                } else{
                    if(!$ppc->check_url_exist(str_replace(' ','',$this->name . '.' . $this->mname . '.' . $this->lname))){
                        $ent_user->provider_url = str_replace(' ','',$this->name . '.' . $this->mname . '.' . $this->lname);
                    }else{
                        do {
                            $random_number = str_pad(mt_rand(0, 9999) . '', 4, '0', STR_PAD_LEFT);
                            $ppc->check_url_exist(str_replace(' ','',$this->name, $random_number)); 
                        } while (!empty($ent_repeated_url));
                        
                        $ent_user->provider_url = str_replace(' ','',$this->name . $random_number);
                    }
                }
            }
        }
        
        $ent_profile = $this->DataProviderProfile->newEntity($arr_save);
        $this->DataProviderProfile->save($ent_profile);
        $this->SysUsers->save($ent_user);
    }
    $this->provider_url = $ent_user->provider_url; 
}

public function set_rating(){
    $ent_provider = $this->DataProviderProfile->find()->where(['DataProviderProfile.injector_id' => $this->id_provider])->first();

    $rating = 0;
    $show_rating = $ent_provider->show_rating;
    $conditions = array(
        'injector_id' => $this->id_provider,    
        'deleted' => 0, 
        'score >' => 0,
    );      


    $this->loadModel('SpaLiveV1.DataTreatmentReviews');       
    $query = $this->DataTreatmentReviews->find();
    $query->select([
        'rating' => $query->func()->avg('score')
    ]);
    $query->where($conditions);
    
    $total_reviews = $this->DataTreatmentReviews->find()->where($conditions)->count();

    $rating = $query->first()->rating;

    $this->rating = array(
        'show_rating' => $show_rating,    
        'rating' => isset($rating) ? round($rating) : 0,
        'total_reviews' => $total_reviews,
    );
}

public function set_services(){
    $services = array();

    $this->loadModel('SpaLiveV1.DataTreatmentsPrice');
    $this->loadModel('SpaLiveV1.DataTreatmentsEnabledByState');

    $existsSubq = $this->DataTreatmentsEnabledByState->find()
    ->select(['DataTreatmentsEnabledByState.treatment_id'])
    ->where([
        'DataTreatmentsEnabledByState.treatment_id = CTCI.id',
        'DataTreatmentsEnabledByState.state_id' => $this->state_id,
    ]);

    $p_entity = $this->DataTreatmentsPrice->find()
    ->select([
        'CTCI.id',
        'CTCI.treatment_id',
        'CTCI.name',
        'CTCI.product_id',
        'CTCI.details',
        'CTC.name',
        'CTC.type',
        'price' => 'DataTreatmentsPrice.price',
        'CTCI.description',
        'STOT.description_injector',
        'STOT.description_patient',
        'STOT.image',
    ])
    ->join([
        'CTCI' => [
            'table' => 'cat_treatments_ci',
            'type'  => 'INNER',
            'conditions' => [
                'DataTreatmentsPrice.treatment_id = CTCI.id',
                'CTCI.deleted' => 0,
            ],
        ],
        'CTC' => [
            'table' => 'cat_treatments_category',
            'type'  => 'INNER',
            'conditions' => 'CTC.id = CTCI.category_treatment_id',
        ],
        'STOT' => [
            'table' => 'sys_treatments_ot',
            'type'  => 'LEFT',
            'conditions' => 'STOT.name = CTCI.name',
        ],
    ])
    ->where([
        'DataTreatmentsPrice.user_id' => $this->id_provider,
        'DataTreatmentsPrice.deleted' => 0,
    ])
    ->where(function ($exp) use ($existsSubq) {
        return $exp->exists($existsSubq);
    })
    ->all();
    
    foreach ($p_entity as $row) {

        if($row['name'] == 'Let my provider help me decide') { continue; }

        $t_array = array(
            'id'         => $row['CTCI']['id'],
            'name'       => $row['CTCI']['name'],
            'details'    => $row['CTCI']['details'],
            'exam_id'    => $row['CTCI']['treatment_id'],
            'product_id' => $row['CTCI']['product_id'],            
            'type'       => $row['CTC']['type'],
            'category'   => $row['CTC']['name'],
            'price'      => $row['price'],
            'description'=> $row['CTCI']['description'],
            'ot_description_injector' => $row['STOT']['description_injector'],
            'ot_description_patient' => $row['STOT']['description_patient'],
            'ot_image' => $row['STOT']['image'],
        ); 
                            
        $services[] = $t_array;
    }      

    $count = count(array_filter($services, function($item) {
        return $item["category"] === "Basic Neurotoxins";
    }));

    if($count > 0){     
        $this->loadModel('SpaLiveV1.CatTreatmentsCi');  

        $no_preference = $this->CatTreatmentsCi->find()
            ->select([
                'id',
                'treatment_id',
                'name',
                'product_id',
                'details',
                'CTC.name', 
                'CTC.type',
                'description'
            ])
            ->join([                
                'CTC' =>  ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'CTC.id = CatTreatmentsCi.category_treatment_id'],
            ])
            ->where(['CTC.name' => 'Basic Neurotoxins', 'CatTreatmentsCi.deleted' => 0, 'CatTreatmentsCi.name' => 'Let my provider help me decide'])
            ->order(['CatTreatmentsCi.id' => 'DESC'])->first();
        
        if(!empty($no_preference)){
            array_push($services, 
                array(
                    'id'         => strval($no_preference['id']),
                    'name'       => strval($no_preference['name']),
                    'details'    => strval($no_preference['details']),
                    'exam_id'    => strval($no_preference['treatment_id']),
                    'product_id' => strval($no_preference['product_id']),            
                    'type'       => strval($no_preference['CTC']['type']),
                    'category'   => strval($no_preference['CTC']['name']),
                    'price'      => 0,
                    'description'=> empty($no_preference['description']) ? '' : $no_preference['description'],
                )
            );
        }
    }        

    $this->services = $services;
}

public function set_body_areas(){
    $FC = new FillersController();
    
    $body_areas = $FC->get_body_areas_fillers();

    $areas_injector_arr = $FC->get_body_areas_injector($this->id_provider);            
    $areas_injector     = !empty($areas_injector_arr['ids']) ?  explode(',', $areas_injector_arr['ids']) : array();
    
    $selected_areas = array();
    foreach($body_areas as $area){
        if(in_array($area['id'], $areas_injector)){
            $selected_areas[] = $area['name'];        
        }
    }

    $this->body_areas = $selected_areas;
}

public function set_categorized_services(){
    $temp_services = $this->services;

    $categories = array();

    foreach($temp_services as $row){
        if($row['type'] == "NEUROTOXINS BASIC" || $row['type'] == "NEUROTOXINS ADVANCED"){
            $categories[] = "NEUROTOXIN";
        } else if(!in_array($row['type'], $categories)){
            $categories[] = $row['type'];
        }
    }

    $filter_categories = array();

    foreach($categories as $cat){
        $temp_array = array();
        foreach($temp_services as $row){
            if($row['name'] == 'Let my provider help me decide'){
                continue;
            }
            if($cat == "NEUROTOXIN"){
                if($row['type'] == "NEUROTOXINS BASIC" || $row['type'] == "NEUROTOXINS ADVANCED"){
                    $temp_array[] = $row;
                } 
            } else {
                if($row['type'] == $cat){
                    $temp_array[] = $row;
                }
            }
            
        }

        $filter_categories[$cat] = $temp_array;
    }

    // TEMPORAL
    if (isset($filter_categories['IV THERAPY']) && count($filter_categories['IV THERAPY']) < 3) {
        $fix_array = $filter_categories['IV THERAPY'];
        $fix_array[] = $fix_array[0];
        $filter_categories['IV THERAPY'] = $fix_array;
    }

    $fixed_categories = array();

    foreach($filter_categories as $key => $row){
        $fixed_categories[] = array(
            'name' => $key,
            'services' => $row
        );
    }

    $this->categorized_services = $fixed_categories;
}

public function set_tags(){
    $categories = array();

    foreach($this->services as $row){
        if($row['type'] == "NEUROTOXINS BASIC" || $row['type'] == "NEUROTOXINS ADVANCED"){
            $categories[] = "NEUROTOXIN";
        } else if(!in_array($row['type'], $categories)){
            $categories[] = $row['type'];
        }
    }

    $filter_categories = array();

    foreach($categories as $cat){
        $temp_array = array();
        foreach($this->services as $row){
            if($row['name'] == 'Let my provider help me decide'){
                continue;
            }
            if($cat == "NEUROTOXIN"){
                if($row['type'] == "NEUROTOXINS BASIC" || $row['type'] == "NEUROTOXINS ADVANCED"){
                    $temp_array[] = $row;
                } 
            } else {
                if($row['type'] == $cat){
                    $temp_array[] = $row;
                }
            }
            
        }

        $filter_categories[$cat] = $temp_array;
    }

    $tags = array();

    if(!empty($filter_categories["NEUROTOXIN"])){
        $neuro_services = count($filter_categories["NEUROTOXIN"]) > 3 
            ? array_slice($filter_categories["NEUROTOXIN"], 0, 3) 
            : $filter_categories["NEUROTOXIN"];
        
        foreach($neuro_services as $row){
            $tags[] = array(
                'name'  => $row['name'],
                'color' => "NEUROTOXIN",   
            );
        }
    }

    if(!empty($filter_categories["IV THERAPY"])){
        $tags[] = array(
            'name'  => 'IV Therapy',
            'color' => "IV THERAPY",   
        );
    }

    if(!empty($filter_categories["FILLERS"])){
        $tags[] = array(
            'name'  => 'Filler treatments',
            'color' => 'FILLERS',   
        );
    }

    $this->tags = $tags;
}

public function set_certifications(){
    $cc = new CourseController();                        
    $trainings_user = $cc->get_courses_user_for_profile($this->id_provider);
    
    unset($trainings_user["courses"]);
    
    $this->certifications = $trainings_user;
}

public function set_schools_certifications(){
    $cc = new CourseController();                        
    $schools_user = $cc->get_schools_certifications($this->id_provider);
    
    $this->schools_certifications = $schools_user;
}

public function set_other_treatments_certifications(){
    $cc = new CourseController();                        
    $other_treatments_certifications = $cc->get_other_treatments_certifications($this->id_provider);
    
    $this->other_treatments_certifications = $other_treatments_certifications;
}

public function set_categorized_certificates(){
    $temp_services = $this->schools_certifications;

    $categories = array();

    foreach($temp_services as $row){
        if($row['type'] == "NEUROTOXINS BASIC" || $row['type'] == "NEUROTOXINS ADVANCED" || $row['type'] == "BOTH NEUROTOXINS"){
            $categories[] = "NEUROTOXIN";
        } else if(!in_array($row['type'], $categories)){
            $categories[] = $row['type'];
        }
    }
    sort($categories);

    $filter_categories = array();

    foreach($categories as $cat){
        $temp_array = array();
        foreach($temp_services as $row){
            if($cat == "NEUROTOXIN"){
                if($row['type'] == "NEUROTOXINS BASIC" || $row['type'] == "NEUROTOXINS ADVANCED" || $row['type'] == "BOTH NEUROTOXINS"){
                    $temp_array[] = $row;
                } 
            } else {
                if($row['type'] == $cat){
                    $temp_array[] = $row;
                }
            }            
        }
        $filter_categories[$cat] = $temp_array;
    }

    $fixed_categories = array();

    foreach($filter_categories as $key => $row){
        $fixed_categories[] = array(
            'name' => $key,
            'certifications' => $row
        );
    }

    $this->categorized_certifications = $fixed_categories;
}

public function set_reviews(){
    //$ent_provider = $this->DataProviderProfile->find()->where(['DataProviderProfile.injector_id' => $this->id_provider])->first();

    $reviews = array();
    //if(isset($ent_provider->reviews)){
        //$reviews_id = explode(",", $ent_provider->reviews);
        
        //$ent_reviews = $this->DataTreatmentReviews->find()->where(['DataTreatmentReviews.id IN' => $reviews_id])->all();

        $select = array(
            'DataTreatmentReviews.id',
            'DataTreatmentReviews.comments',
            'DataTreatmentReviews.created',
            'DataTreatmentReviews.score',
            'Patient.name',
            'Patient.lname'
        );
        $select['treatments'] = "(SELECT GROUP_CONCAT(CONCAT(CTC.name,'') SEPARATOR ', ') 
                                                FROM cat_treatments_ci CT 
                                                JOIN cat_treatments_category CTC ON CTC.id = CT.category_treatment_id 
                                                WHERE FIND_IN_SET(CT.id,DataTreatment.treatments) LIMIT 1)";
        $ent_reviews = $this->DataTreatmentReviews->find()
            ->select($select)
            ->join([
                'DataTreatment' => [
                    'table' => 'data_treatment',
                    'type' => 'INNER',
                    'conditions' => 'DataTreatment.id = DataTreatmentReviews.treatment_id'
                ],
                'Patient' => [
                    'table' => 'sys_users',
                    'type' => 'INNER',
                    'conditions' => 'Patient.id = DataTreatment.patient_id'
                ]                
            ])
            ->where(function ($exp, $q) {
                return $exp->isNotNull('comments')
                            ->and($exp->notLike('comments', '%No comments%'));
            })
            ->andWhere([
                'DataTreatmentReviews.injector_id' => $this->id_provider, 
                'DataTreatmentReviews.deleted' => 0])
            ->all();
        
        foreach($ent_reviews as $row){

            // $treatments = $this->getTreatmentsName($row['DataTreatment']->treatments);

            $reviews[] = array(
                'id' => $row->id,
                'comments' => $row->comments,
                'created' => $row->created,
                'score' => $row->score,  
                'patient' => $row['Patient']['name'] . ' ' . substr(ucfirst($row['Patient']['lname']), 0, 1),
                'treatments' => $row->treatments
            );
        }
    //}

    $this->reviews = $reviews;
}       

public function set_treatment_options(){
    $SC = new SubscriptionController();
    $this->treatment_options = $SC->get_treatment_options($this->id_provider);
}

public function set_before_after(){
    $ent_provider = $this->DataProviderProfile->find()->where(['DataProviderProfile.injector_id' => $this->id_provider])->first();

    $before_after = array();
    if(isset($ent_provider->before_after)){
        $ba = explode('|', $ent_provider->before_after);
        foreach ($ba as $item) {
            $ba_item = explode(',', $item);
            $before_after[] = array(
                'before' => $ba_item[0],
                'after'  => $ba_item[1]
            );
        }
    }

    $this->before_after = $before_after;
}

public function set_instagram_profile(){
    $ent_provider = $this->DataProviderProfile->find()->where(['DataProviderProfile.injector_id' => $this->id_provider])->first();
    
    if(!isset($ent_provider->instagram_profile)){
        $instagram_profile = array(
            'show_instagram'    => false,
            'instagram_profile' => '',    
            'is_profile_setup'  => false,            
        );
    }else{

        $instagram_profile = json_decode($ent_provider->instagram_profile);

        $instagram_profile = array(
            'show_instagram'    => $instagram_profile->show_instagram,  
            'instagram_profile' => $instagram_profile->instagram_profile,           
            'is_profile_setup'  => $instagram_profile->is_profile_setup,               
        );
    }

    $this->instagram_profile = $instagram_profile;
}

public function set_featured(){
    $ent_provider = $this->DataProviderProfile->find()->where(['DataProviderProfile.injector_id' => $this->id_provider])->first();

    $featured = array();
    if(isset($ent_provider->featured)){
        $featured = explode(',', $ent_provider->featured);  
    }

    $this->featured = $featured;
}

public function set_images(){
    $ent_provider = $this->DataProviderProfile->find()->where(['DataProviderProfile.injector_id' => $this->id_provider])->first();

    $images = array();
    if(isset($ent_provider->images)){
        $images = explode(',', $ent_provider->images);  
    }

    $this->images = $images;
}

public function set_promos(){
    $this->loadModel('SpaLiveV1.DataPromoDay');
    $now = date('Y-m-d H:i:s');
    $promos = $this->DataPromoDay->find()->select(['DataPromoDay.name', 'DataPromoDay.discount_type', 'DataPromoDay.amount', 'DataPromoDay.categories_id', 'DataPromoDay.start_date', 'DataPromoDay.public' ,'DataPromoDay.end_date', 'Patients.patients_id'])
    ->join([
        'Patients' => [
            'table' => 'data_patients_promo_day',
            'type' => 'INNER',
            'conditions' => 'Patients.promo_id = DataPromoDay.id'
        ],
    ])
    ->where(['DataPromoDay.user_id' => $this->id_provider, 'DataPromoDay.deleted' => 0, 'DataPromoDay.status' => 'ACTIVE', '(DATE_FORMAT(DataPromoDay.end_date, "%Y-%m-%d %H:%i:%s") > "' . $now . '")'])->all();

    $result = array();
    if(Count($promos) > 0){
        foreach($promos as $key => $promo){
            $categories = explode(',', $promo->categories_id);
            $categories_name = array();
            foreach($categories as $category){
                $this->loadModel('SpaLiveV1.CatTreatmentsPromoDay');
                $category_name = $this->CatTreatmentsPromoDay->find()->select(['name'])->where(['id' => $category])->first();
                $categories_name[] = $category_name->name;
            }
            $string_categories = implode(', ', $categories_name);
            
            if($promo->public == 1){
                $reg2 = $promo->discount_type == 'percentage' ? $promo->amount . '% discount on ' . $string_categories : '$' . $promo->amount / 100 . ' discount on ' . $string_categories; 
                $result[] = array(
                    'reg1' => $promo->name,
                    'reg2' => $reg2,
                    'reg3' => 'From ' . date('m/d/Y', strtotime($promo->start_date->i18nFormat('yyyy-MM-dd HH:mm'))) . ' to ' . date('m/d/Y', strtotime($promo->end_date->i18nFormat('yyyy-MM-dd HH:mm'))),
                );
            } else{
                if($this->patient_id > 0){
                    $ids = explode(',', $promo->patients_id);
                    if(in_array($this->patient_id, $ids)){
                        $reg2 = $promo->discount_type == 'percentage' ? $promo->amount . '% discount on ' . $string_categories : '$' . $promo->amount / 100 . ' discount on ' . $string_categories; 
                        $result[] = array(
                            'reg1' => $promo->name,
                            'reg2' => $reg2,
                            'reg3' => 'From ' . date('m/d/Y', strtotime($promo->start_date->i18nFormat('yyyy-MM-dd HH:mm'))) . ' to ' . date('m/d/Y', strtotime($promo->end_date->i18nFormat('yyyy-MM-dd HH:mm'))),
                        );
                    }
                }
            }
        }

    }
    
    $this->promos = $result;

}

public function set_certification_medical(){
    $this->loadModel('SpaLiveV1.DataTrainings');
    $this->loadModel('SpaLiveV1.CatTrainings');

    $fields = [
        'CatTrainings.id',
        'CatTrainings.title',
        'CatTrainings.scheduled',
        'CatTrainings.address',
        'CatTrainings.city',
        'CatTrainings.state_id',
        'CatTrainings.zip',
        'CatTrainings.level',    
        'CatTrainings.created',
        'data_training' => 'DataTrainings.id', 
        'attended' => 'DataTrainings.attended'
    ];
    $training = $this->CatTrainings->find('all', [
        'conditions' => [
            'DataTrainings.user_id' => $this->id_provider
        ],            
    ])
    ->select($fields)
    ->join([
        'table' => 'data_trainings',
        'alias' => 'DataTrainings',
        'type' => 'INNER',
        'conditions' => 'CatTrainings.id = DataTrainings.training_id'
    ])
    ->where([
        'DataTrainings.attended' => 1,
        'DataTrainings.deleted' => 0,
        'CatTrainings.deleted' => 0,
        'CatTrainings.level IN' => array('LEVEL 3 MEDICAL')
    ])
    ->all();

    $this->certification_medical = $training;
    $this->has_medical = count($training) > 0 ? true : false;
}

public function set_subscriptions_status(){
    
    $this->loadModel('SpaLiveV1.DataSubscriptions');     
    $str_query_find     = "  SELECT  count(DS.id) amount	        
                FROM data_subscriptions DS 
                
                WHERE                
                subscription_type like  'SUBSCRIPTIONMD%' AND
                deleted = 0 AND
                status = 'ACTIVE' AND
                #SU.name NOT LIKE '%Tester%' AND                               
                user_id = '{$this->id_provider}'
                ";
    $subscriptions  = $this->DataSubscriptions->getConnection()->execute($str_query_find)->fetchAll('assoc'); 
    if($subscriptions[0]['amount']>0){
        $this->subscription_status = true;
    }else{
        $this->subscription_status = false;
    }
}

public function set_provide_fillers(){
    $this->loadModel('SpaLiveV1.DataSubscriptions');

    $ent_sub = $this->DataSubscriptions->find()->where(['user_id' => $this->id_provider, 'subscription_type LIKE' => '%MD%', 'status' => 'ACTIVE', 'deleted' => 0])->first();

    if(!empty($ent_sub)){
        if(strpos($ent_sub->subscription_type, 'FILLERS') !== false){
            $this->provide_fillers = true;
        } else{
            $this->provide_fillers = false;
        }
    } else{
        $this->provide_fillers = false;
    }
}

// IN ORDER TO CREATE A INSTANCE FOR THIS CLASS, USE THE FOLLOWING METHOD
public static function get_profile_data($uid_provider, $patient_id = 0){
    $temp_profile = new ProviderProfile($uid_provider, true, $patient_id);
    $profile      = new ProviderProfile($uid_provider, false, $patient_id);
    
    //Data From User  
    $profile->id_provider                = $temp_profile->id_provider;  
    $profile->name                       = $temp_profile->name;
    $profile->lname                      = $temp_profile->lname; 
    $profile->mname                      = $temp_profile->mname;
    $profile->gender                     = $temp_profile->gender;
    $profile->profile_image              = $temp_profile->profile_image; 
    $profile->description                = $temp_profile->description;   
    $profile->provider_url               = $temp_profile->provider_url;  
    $profile->featured                   = $temp_profile->featured;
    $profile->images                     = $temp_profile->images;
    //Data From Provider
    $profile->services                   = $temp_profile->services;
    $profile->categorized_services       = $temp_profile->categorized_services;
    $profile->body_areas                 = $temp_profile->body_areas;
    $profile->tags                       = $temp_profile->tags;
    $profile->certifications             = $temp_profile->certifications;
    $profile->schools_certifications     = $temp_profile->schools_certifications;
    $profile->categorized_certifications = $temp_profile->categorized_certifications;
    $profile->other_treatments_certifications = $temp_profile->other_treatments_certifications;
    $profile->treatment_options          = $temp_profile->treatment_options;
    $profile->provide_fillers            = $temp_profile->provide_fillers;
    //Other Stuff
    $profile->reviews                    = $temp_profile->reviews;
    $profile->rating                     = $temp_profile->rating;
    $profile->before_after               = $temp_profile->before_after;
    $profile->instagram_profile          = $temp_profile->instagram_profile;
    $profile->promos                     = $temp_profile->promos;
    $profile->certification_medical      = $temp_profile->certification_medical;
    $profile->has_medical                = $temp_profile->has_medical;
    $profile->subscription_status        = $temp_profile->subscription_status;
    unset($profile->paginate);

    return $profile;
}       

#endregion
}