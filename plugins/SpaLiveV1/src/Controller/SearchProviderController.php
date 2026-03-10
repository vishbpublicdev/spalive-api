<?php
declare(strict_types=1);

namespace SpaLiveV1\Controller;

#region IMPORTS
use App\Controller\AppPluginController;
use Cake\Database\FunctionsBuilder;
use Cake\Database\Expression\QueryExpression;
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
use SpaLiveV1\Controller\Data\ProviderProfile;
#endregion

class SearchProviderController extends AppPluginController {
    
    #region INITIALIZE
    private $total = 3900;
    private $paymente_gfe = 1800;
    private $register_total = 79500;
    private $register_refund = 3500;
    private $shipping_cost = 1000;
    private $shipping_cost_both = 3000;
    private $shipping_cost_inj = 2000;
    private $shipping_cost_mat = 1000;
    private $shipping_cost_misc = 1000;
    private $training_advanced = 89500;
    private $emergencyPhone = "9035301512";
    private $total_subscriptionmsl = 3995;
    private $total_subscriptionmd = 17900;
    private $URL_API = "";
    private $URL_WEB = "";
    private $URL_ASSETS = "";
    private $URL_PANEL = "";
    private $str_name;
    private $where_provider_condition = array(
        'SysUsers.type IN'   => array('injector', 'gfe+ci'),   
        'SysUsers.deleted'   => 0,
        'SysUsers.steps'     => 'HOME',  
        'SysUsers.active'    => 1,       
    );
    private $categories = array(
        // array(
        //     'id'    => 'ALL',
        //     'name'  => 'All',
        // ),
        array(
            'id'    => 'NEUROTOXINS',
            'name'  => 'Neurotoxins',
        ),
        array(
            'id'  => 'FILLERS',
            'name'  => 'Fillers',
        ),
        array(
            'id'  => 'THERAPY',
            'name'  => 'IV Therapy',
        )
    );

    public function initialize() : void {
        parent::initialize();
        date_default_timezone_set("America/Chicago");
        $this->URL_API = env('URL_API', 'https://api.myspalive.com/');
        $this->URL_WEB = env('URL_WEB', 'https://app.myspalive.com/');
        $this->URL_ASSETS = env('URL_ASSETS', 'https://api.myspalive.com/assets/');
        $this->URL_PANEL = env('URL_PANEL', 'https://panel.myspalive.com/');
        $this->loadModel('SpaLiveV1.AppToken');
        $this->loadModel('SpaLiveV1.CatStates');
        $this->loadModel('SpaLiveV1.CatProducts');
        
        \Stripe\Stripe::setApiKey(Configure::read('App.stripe_secret_key'));   
        
        $token = get('token',"");
        if(isset($token)){
            $user = $this->AppToken->checkToken($token);
            if($user !== false){
                $state = $this->CatStates->find()->select(['CatStates.cost_ci', 'CatStates.refund_ci', 'CatStates.shipping_cost', 'CatStates.cost_gfe', 'CatStates.payment_gfe','CatStates.phone_number'])->where(['CatStates.id' => $user['user_state']])->first();
                if(!empty($state)){
                    $this->register_total = $state->cost_ci > 0 ? $state->cost_ci : $this->register_total;
                    $this->register_refund = $state->refund_ci > 0 ? $state->refund_ci : $this->register_refund;
                    $this->shipping_cost = $state->shipping_cost > 0 ? $state->shipping_cost : $this->shipping_cost;
                    
                    $this->shipping_cost_both = $state->shipping_cost_both > 0 ? $state->shipping_cost_both : $this->shipping_cost_both;
                    $this->shipping_cost_inj = $state->shipping_cost_inj > 0 ? $state->shipping_cost_inj : $this->shipping_cost_inj;
                    $this->shipping_cost_mat = $state->shipping_cost_mat > 0 ? $state->shipping_cost_mat : $this->shipping_cost_mat;

                    $this->total = $state->cost_gfe > 0 ? $state->cost_gfe : $this->total;
                    $this->paymente_gfe = $state->payment_gfe > 0 ? $state->payment_gfe : $this->paymente_gfe;
                    $this->emergencyPhone = $state->phone_number != '' ? $state->phone_number : $this->emergencyPhone;
                    $this->total_subscriptionmsl = $state->price_sub_msl > 0 ? $state->price_sub_msl : $this->total_subscriptionmsl;
                    $this->total_subscriptionmd = $state->price_sub_md > 0 ? $state->price_sub_md : $this->total_subscriptionmd;
                }
            }

            $ver = get('version', '');
            $ver = str_replace('version ', '', $ver);
        } else {
            // TEXAS
            $state = $this->CatStates->find()->select(['CatStates.cost_ci', 'CatStates.refund_ci', 'CatStates.shipping_cost', 'CatStates.cost_gfe', 'CatStates.payment_gfe'])->where(['CatStates.id' => 43])->first();
            if(!empty($state)){
                $this->register_total = $state->cost_ci > 0 ? $state->cost_ci : $this->register_total;
                $this->register_refund = $state->refund_ci > 0 ? $state->refund_ci : $this->register_refund;
                $this->shipping_cost = $state->shipping_cost > 0 ? $state->shipping_cost : $this->shipping_cost;
                
                $this->shipping_cost_both = $state->shipping_cost_both > 0 ? $state->shipping_cost_both : $this->shipping_cost_both;
                $this->shipping_cost_inj = $state->shipping_cost_inj > 0 ? $state->shipping_cost_inj : $this->shipping_cost_inj;
                $this->shipping_cost_mat = $state->shipping_cost_mat > 0 ? $state->shipping_cost_mat : $this->shipping_cost_mat;

                $this->total = $state->cost_gfe > 0 ? $state->cost_gfe : $this->total;
                $this->paymente_gfe = $state->payment_gfe > 0 ? $state->payment_gfe : $this->paymente_gfe;
                $this->emergencyPhone = $state->phone_number != '' ? $state->phone_number : $this->emergencyPhone;
                $this->total_subscriptionmsl = $state->price_sub_msl > 0 ? $state->price_sub_msl : $this->total_subscriptionmsl;
                $this->total_subscriptionmd = $state->price_sub_md > 0 ? $state->price_sub_md : $this->total_subscriptionmd;
            }
        }
        $product = $this->CatProducts->find()->select(['CatProducts.unit_price'])->where(['CatProducts.id' => 44])->first();
        if(!empty($product)){
            $this->training_advanced = $product->unit_price > 0 ? $product->unit_price : $this->training_advanced;
        }
    }
    #endregion

    #region ENDPOINTS

    public function search()
    {
        $token = get('token', '');

        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false) {
                $state = 0;
            }
            else{
                $state = USER_STATE;
            }
        }else{
            $state = 0;
        }

        $page   = get('page', 1);
        $order  = get('order', 'ASC');
        $limit  = get('limit', 10);
        $search = get('search', '');
        $treatments = get('treatments', '');
        $providers = $this->find_providers($page, $order, $limit, $search, $state, $treatments);  
        
        $this->set('provider_profiles', $providers['data']);    
        $this->set('total', $providers['total']);   
        $this->set('has_more', $providers['has_more']);
        $this->set('has_previous', $providers['has_previous']);  

        $this->success();   
    }

    public function search_iv_therapy()
    {
        $token = get('token', '');

        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false) {
                $state = 0;
            }
            else{
                $state = USER_STATE;
            }
        }else{
            $state = 0;
        }

        $page   = get('page', 1);
        $order  = get('order', 'ASC');
        $limit  = get('limit', 10);
        $search = get('search', '');    
        $providers = $this->find_providers_iv_therapy($page, $order, $limit, $search, $state);  
        
        $this->set('provider_profiles', $providers['data']);    
        $this->set('total', $providers['total']);   
        $this->set('has_more', $providers['has_more']);
        $this->set('has_previous', $providers['has_previous']);  

        $this->success();   
    }
    
    public function search_by_zip(){
        $set_size = get('set_size', 5);
        $zip      = get('zip', 0);
        $order    = get('order', 'most_popular');
        $page     = get('page', 1);  
        $exclude  = get('exclude', '');  
        $exclude_arr  = explode(',', $exclude);

        if($zip > 0){

        }else{
            $this->message("zip in not a valid number");
            return;
        }
        $providers = $this->find_provider_by_zip($set_size, $exclude_arr, $zip, $order, $page);

        $this->set('provider_profiles', $providers['data']);    
        $this->set('total', $providers['total']);   
        $this->set('has_more', $providers['has_more']);
        $this->set('has_previous', $providers['has_previous']);  
        
        $this->success();
    }

    public function provider_categories(){
        $show_all = get('show_all', false);
        if($show_all){
            $this->set('categories', $this->categories);
            $this->success();            
        }else{            
            $allowed_iv = array("NEUROTOXINS", "THERAPY");
            $arr_cat = array();

            foreach ($this->categories as $key => $value) {
                if(in_array($value['id'], $allowed_iv)){
                    $arr_cat[] = $value;
                }
            }

            $this->set('categories', $arr_cat);
            $this->success();
        }
    }

    public function search_for_providers(){
        $token = get('token', '');

        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false) {
                $state = 0;
            }
            else{
                $state = USER_STATE;
            }
        }else{
            $state = 0;
        }

        $set_size   = get('set_size', 5);
        $zip        = get('zip', 0);
        $order      = get('order', 'most_popular');
        $page       = get('page', 1);  
        $exclude    = get('exclude', '');
        $cats       = get('categories', 'NEUROTOXINS');

        $categories_arr = explode(',', $cats);

        $exclude_arr  = explode(',', $exclude);

        if($zip <= 0){
            $this->message("zip in not a valid number");
            return;
        }
        $providers = $this->find_provider_by_zip(
            $set_size, 
            $exclude_arr, 
            $zip, 
            $order, 
            $page,
            $categories_arr,
            $state
        );

        $this->set('provider_profiles', $providers['data']);    
        $this->set('total', $providers['total']);   
        $this->set('has_more', $providers['has_more']);
        $this->set('has_previous', $providers['has_previous']);  
        
        $this->success();
    }

    public function search_by_zip_or_treatments(){
        $zip = get('zip', 0);
        $treatment_ids = get('treatments_id', '');
        $exclude = get('exclude', '');  

        $exclude = ($exclude == '') ? [] : [$exclude];                      

        $page = get('page', 1);
        $limit = get('limit', 5);

        if($zip > 0){
            $providers = $this->find_provider_by_zip(
                $limit, 
                $exclude, 
                $zip, 
                $order = 'most_popular', 
                $page
            );
        }else{
            $providers = $this->find_provider_by_treatments(
                $limit, 
                $exclude, 
                $treatment_ids,
                $page
            );
        }

        $this->set('provider_profiles', $providers['data']);    
        $this->set('total', $providers['total']);   
        $this->set('has_more', $providers['has_more']);
        $this->set('has_previous', $providers['has_previous']);  
        
        $this->success();
    }

    #endregion
    
    #region FUNCTIONS

    // Return an array of profiles from the providers that meet the search criteria.    
    private function find_providers($page = 0, $order = 'ASC', $limit = 10, $search = '', $state = 0, $treatments = ''){
        $this->loadModel('SpaLiveV1.SysUsers');          
        $where = $this->where_provider_condition;

        if($state > 0){
            $where['SysUsers.state'] = $state;
        }

        if($search != ''){
            if(filter_var($search, FILTER_VALIDATE_EMAIL)) {
                $where['SysUsers.email'] = $search; 
            } else {
                $search = trim($search);             
                $arr_val = explode(' ', str_replace('@', '', $search));
                $matchValue = '';
                $sep = '';
                foreach ($arr_val as $value) {
                    $matchValue .= $sep.'+'.$value.'*';
                    $sep = ' ';
                }
                $where[] = "MATCH(SysUsers.name,SysUsers.mname,SysUsers.lname) AGAINST ('{$matchValue}' IN BOOLEAN MODE)";        
            }  
        }                           

        $select = [ 'uid' ];
        $providers = $this->SysUsers->find()            
            ->select($select)
            ->where($where)
            ->limit($limit)
            ->offset(($page - 1) * $limit)      
            ->order(['SysUsers.id' => $order])            
            ->all();    

        $provider_profiles = array();

        foreach ($providers as $key => $provider) {            
            $provider_profile     = ProviderProfile::get_profile_data($provider->uid);
            if (strpos(strtolower($provider_profile->name), 'test') !== false || strpos(strtolower($provider_profile->lname), 'test') !== false || strpos(strtolower($provider_profile->mname), 'test') !== false) {
                continue;
            }
            if(!$provider_profile->subscription_status){
                continue;
            }

            if($treatments == '1033' && !$provider_profile->provide_fillers){
                continue;
            }
            
            $provider_profiles[]  = $provider_profile;            
        } 

        $total = $providers = $this->SysUsers->find()            
            ->select($select)
            ->where($where)
            ->count();  

        return array(
            'total'        => $total,
            'data'         => $provider_profiles,
            'has_more'     => $total > ($page * $limit) ? true : false,
            'has_previous' => $page > 1 ? true : false,
        );
    }

    // Return an array of profiles from the providers that meet the search criteria.    
    private function find_providers_iv_therapy($page = 0, $order = 'ASC', $limit = 10, $search = '', $state = 0){
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataTrainings');
        $this->loadModel('SpaLiveV1.CatTrainings');
        $where = $this->where_provider_condition;

        if($state > 0){
            $where['SysUsers.state'] = $state;
        }

        if($search != ''){
            if(filter_var($search, FILTER_VALIDATE_EMAIL)) {
                $where['SysUsers.email'] = $search; 
            } else {
                $search = trim($search);             
                $arr_val = explode(' ', str_replace('@', '', $search));
                $matchValue = '';
                $sep = '';
                foreach ($arr_val as $value) {
                    $matchValue .= $sep.'+'.$value.'*';
                    $sep = ' ';
                }
                $where[] = "MATCH(SysUsers.name,SysUsers.mname,SysUsers.lname) AGAINST ('{$matchValue}' IN BOOLEAN MODE)";        
            }  
        }                           

        $select = [ 'uid' ];
        $providers = $this->SysUsers->find()            
            ->select($select)
            ->where($where)
            ->limit($limit)
            ->offset(($page - 1) * $limit)      
            ->order(['SysUsers.id' => $order])            
            ->all();    

        $provider_profiles = array();


        foreach ($providers as $key => $provider) {            
            $provider_profile     = ProviderProfile::get_profile_data($provider->uid);
            if (strpos(strtolower($provider_profile->name), 'test') !== false || strpos(strtolower($provider_profile->lname), 'test') !== false || strpos(strtolower($provider_profile->mname), 'test') !== false) {
                continue;
            }
            //$provider_profileres = $provider_profile;
            $injectorIV = $this->SysUsers->find()->select(['SysUsers.id', 'SysUsers.deleted'])->where(['SysUsers.uid' => $provider->uid, 'SysUsers.deleted' => 0])->first();
            $CatTraining = $this->CatTrainings->find()->select(['CatTrainings.id', 'CatTrainings.title', 'CatTrainings.deleted'])->where(['CatTrainings.title' => 'IV Therapy', 'CatTrainings.deleted' => 0])->first();
            $ivTherapyEnrolled = $this->DataTrainings->find()->select(['DataTrainings.user_id','DataTrainings.training_id','DataTrainings.deleted'])->where(['DataTrainings.user_id' => $injectorIV->id, 'DataTrainings.training_id' => $CatTraining->id, 'DataTrainings.deleted' => 0])->first();

            if(!empty($ivTherapyEnrolled)){
              $provider_profiles[]  = $provider_profile;
            }
          
        } 

        $total = $providers = $this->SysUsers->find()            
            ->select($select)
            ->where($where)
            ->count();  

            //$this->set('provider_profileres', $provider_profileres);

        return array(
            'total'        => count($provider_profiles),
            'data'         => $provider_profiles,
            'has_more'     => count($provider_profiles) > ($page * $limit) ? true : false,
            'has_previous' => $page > 1 ? true : false,
        );
    }

    private function find_provider_by_zip(
        $limit = 5, 
        $exclude = array(), 
        $zip = 0, 
        $order = 'most_popular',
        $page = 1,
        $cats = array('ALL'),
        $state = 0
    ){

        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataTrainings');
        $this->loadModel('SpaLiveV1.CatTrainings');
        $where  = $this->where_provider_condition;
        $number_reviews_q = 'IFNULL((SELECT COUNT(*) FROM data_treatment_reviews WHERE data_treatment_reviews.injector_id = SysUsers.id), 0)';
        $score_q = 'IFNULL((SELECT AVG(data_treatment_reviews.score) FROM data_treatment_reviews WHERE data_treatment_reviews.injector_id = SysUsers.id), 0)';
        $select = [ 
            'uid',
            'number_reviews' => $number_reviews_q,
            'score'          => $score_q,
        ];

        if($zip > 0){
            $where['SysUsers.zip'] = $zip;
        }
        $preorder = $order;
        $order = $order == 'most_popular' ? 'number_reviews' : 'score';
        

        $filter_category_arr = array();
        $has_all = in_array('ALL', $cats);

        // CHECK NEURROTOXINS
        if(in_array('NEUROTOXINS', $cats) || $has_all){            
            $neurotoxins  = 'IFNULL( (SELECT COUNT(*) FROM data_trainings DT JOIN cat_trainings CT ON DT.training_id = CT.id WHERE DT.user_id = SysUsers.id AND DT.deleted = 0 AND CT.level != \'LEVEL IV\') ,0)';
            $neurotoxins1 = 'IFNULL( (SELECT COUNT(*) FROM data_courses DC JOIN cat_courses CC ON DC.course_id = CC.id WHERE DC.user_id = SysUsers.id AND DC.deleted = 0 AND CC.type IN (\'NEUROTOXINS BASIC\', \'NEUROTOXINS ADVANCED\', \'BOTH NEUROTOXINS\') AND DC.status = \'DONE\') ,0)';
            $filter_category_arr[] = "($neurotoxins > 0 OR $neurotoxins1 > 0)";
        }

        // CHECK IV THERAPY

        if(in_array('THERAPY', $cats) || $has_all){            
            $neurotoxins = 'IFNULL( (SELECT COUNT(*) FROM data_trainings DT JOIN cat_trainings CT ON DT.training_id = CT.id WHERE DT.user_id = SysUsers.id AND DT.deleted = 0 AND CT.level = \'LEVEL IV\') ,0)';
            $filter_category_arr[] = "($neurotoxins > 0)";
        }

        // CHECK FILLERS

        if(in_array('FILLERS', $cats) || $has_all){            
            $neurotoxins = 'IFNULL( (SELECT COUNT(*) FROM data_courses DC JOIN cat_courses CC ON DC.course_id = CC.id WHERE DC.user_id = SysUsers.id AND DC.deleted = 0 AND CC.type = \'FILLERS\' AND DC.status = \'DONE\') ,0)';
            $filter_category_arr[] = "($neurotoxins > 0)";
        }

        $filter_category = '( ' . implode(' OR ', $filter_category_arr) . ' )';

        require_once(ROOT . DS . 'vendor' . DS  . 'zipcodes' . DS . 'init.php');
        $data = isset(\zipcodes\Zipcodes::DATA[$zip]) ? \zipcodes\Zipcodes::DATA[$zip] : null;
        if ($data) {
            $latitude = $data['lat'];
            $longitude = $data['lng']; 
        } else {

            for($i = 1; $i <= 100; $i++) {
                    $nzip = intval($zip)+$i;
                    $bnzip = 5 - strlen(strval($nzip));
                    $rzip = $nzip;
                    if (strlen(strval($nzip)) < 5) {
                    for($c = 0; $c < $bnzip ;$c++) {
                        $rzip = '0' . $rzip;
                    }
                }
                
                    $data = isset(\zipcodes\Zipcodes::DATA[$rzip]) ? \zipcodes\Zipcodes::DATA[$rzip] : null;
                    if ($data) {
                    $latitude = $data['lat'];
                    $longitude = $data['lng'];
                    break;
                    }

                }

        }


        if(!isset($latitude) || !isset($longitude) ){
            return array(
                'total'        => 0,
                'data'         => [],
                'has_more'     => false,
                'has_previous' => false,
            );
        }

        $str_query_1 = "SELECT SysUsers.uid, $number_reviews_q as number_reviews, $score_q as score, SysUsers.radius, 
                        69.09 * DEGREES(ACOS(LEAST(1.0, COS(RADIANS({$latitude}))
                            * COS(RADIANS(SysUsers.latitude))
                            * COS(RADIANS({$longitude} - SysUsers.longitude))
                            + SIN(RADIANS({$latitude}))
                            * SIN(RADIANS(SysUsers.latitude))))) AS distance_in_mi
                        FROM sys_users as SysUsers 
                        WHERE ";
        if($state > 0){ 
            $str_query_1 .= "SysUsers.state = $state AND "; 
        }

           $str_query_1 .= "SysUsers.type IN ('injector', 'gfe+ci') AND
                            SysUsers.steps = 'HOME' AND
                            SysUsers.active = 1 AND
                            SysUsers.deleted = 0 AND
                            SysUsers.name NOT LIKE '%test%' AND
                            SysUsers.uid NOT IN ('".implode(',', $exclude)."')                             
                            HAVING ($score_q = 0 OR $score_q >= 40 AND distance_in_mi < SysUsers.radius) AND $filter_category";

        // pr($str_query_1); die; // TESTING PURPOSES ⚠️

        $str_query = $str_query_1 . " ORDER BY $order DESC LIMIT $limit OFFSET ".(($page - 1) * $limit);

        $providers_query       = $this->SysUsers->getConnection()->execute($str_query)->fetchAll('assoc');
        $providers_query_count = $this->SysUsers->getConnection()->execute($str_query_1)->fetchAll('assoc');    
        // $providers = $this->SysUsers->find()            
        //     ->select($select)
        //     ->where($where)
        //     //->having(['('. $number_reviews_q. ') >' => 4])
        //     ->limit($limit)
        //     ->order($order == 'most_popular' ? ['number_reviews' => 'DESC'] : ['score' => 'DESC'])            
        //     ->offset(($page - 1) * $limit)    
        //     ->all();

        $provider_profiles = array();                 
        $CatTraining = $this->CatTrainings->find()->select(['CatTrainings.id', 'CatTrainings.title', 'CatTrainings.deleted'])->where(['CatTrainings.title' => 'IV Therapy', 'CatTrainings.deleted' => 0])->first();
        foreach ($providers_query as $key => $provider) {            
            $provider_profile     = ProviderProfile::get_profile_data($provider['uid']);

            $injectorIV = $this->SysUsers->find()->select(['SysUsers.id', 'SysUsers.deleted'])->where(['SysUsers.uid' => $provider['uid'], 'SysUsers.deleted' => 0])->first();
            
            $ivTherapyEnrolled = $this->DataTrainings->find()->select(['DataTrainings.user_id','DataTrainings.training_id','DataTrainings.deleted'])->where(['DataTrainings.user_id' => $injectorIV->id, 'DataTrainings.training_id' => $CatTraining->id, 'DataTrainings.deleted' => 0])->first();

            //if orderer
            //$this->set('tos', $preorder);
            if($preorder == 'IV Therapy'){
                //$this->set('tis', $ivTherapyEnrolled);
                if(!empty($ivTherapyEnrolled)){
                    //$this->set('sus', 'noempty');
                  $provider_profiles[]  = $provider_profile;
                }
            }
            else{
                $provider_profiles[]  = $provider_profile;
            }
          
        } 

        $filteredProviders = array();
        // Filtrar los objetos que cumplen con el criterio
        //$this->set('NEUROTOXINS', in_array('NEUROTOXINS', $cats));
        //$this->set('THERAPY', in_array('THERAPY', $cats));
        //$this->set('hasall', $has_all);
        //$this->set('cond', !in_array('NEUROTOXINS', $cats) || !$has_all);

        if(in_array('THERAPY', $cats)){

            if(!in_array('NEUROTOXINS', $cats) && !$has_all){
                foreach ($provider_profiles as $provider) {
    
                    $hasiv = false;
    
                    if(!empty($provider->services)){
    
                        foreach ($provider->services as $service) {
                            if ($service['id'] >= 1000) {
                                $hasiv = true;
                            }
    
                        }
    
                    }
    
                    if($hasiv){
                        $filteredProviders[]  = $provider;
                    }
                }
    
                $provider_profiles = $filteredProviders;

            } else {
                foreach ($provider_profiles as $provider) {
    
                    $hasiv = false;
    
                    if(!empty($provider->services)){
    
                        foreach ($provider->services as $service) {
                            if ($service['id'] >= 1) {
                                $hasiv = true;
                            }
    
                        }
    
                    }
    
                    if($hasiv){
                        $filteredProviders[]  = $provider;
                    }
                }
    
                $provider_profiles = $filteredProviders;
            }

        }

        
        if($preorder == 'IV Therapy'){
            $total = count($provider_profiles);

        } else {
            $total = count($providers_query_count);
        }

        

        return array(
            'total'        => $total,
            'data'         => $provider_profiles,
            'has_more'     => $total > ($page * $limit) ? true : false,
            'has_previous' => $page > 1 ? true : false,
        );
    }

    private function find_provider_by_treatments(
        $limit = 5, 
        $exclude = array(), 
        $treatment_ids = '',
        $page = 1
    ){
        // PLEASE PAY ATTENTION TO THE CODE ⚠️⚠️⚠️
        // FIRST OF ALL THINK BEFORE YOU START CODING BECAUSE YOU CAN 
        // LEAVE A COMPLETE MESS AND IM THE ONE THAT HAVE TO CLEAN THAT. OKAY, SIS? 😤🫵🏻👎🏻
        // THEY BARELY PAY ME ENOUGH SO STOP WITH THIS NONSENSE OR ASK FOR HELP, LIKE ..⁉️
        // BE SAFE, AND READ 👏🏻 A 👏🏻 BOOK 👏🏻 OR SOMETHING
        // HAPPY HOLIDAYS YALL. 🥱🥳

        $this->loadModel('SpaLiveV1.SysUsers');
        $treatment_ids_arr  = explode(',', $treatment_ids);

        $this->loadModel('SpaLiveV1.CatTreatmentsCi');
        $cats_treatment = $this->CatTreatmentsCi->find()
            ->select(['name' => 'CTC.type'])
            ->join([
                'CTC' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'CTC.id = CatTreatmentsCi.category_treatment_id'],    
            ])
            ->where(['CatTreatmentsCi.id IN ('.$treatment_ids.')'])
            ->group(['CTC.type'])
            ->toArray();      

        $cats_treatment_arr = [];
        foreach($cats_treatment as $cat){
            $cats_treatment_arr[] = "'" . $cat['name'] . "'";
        }

        $cats_treatment_arr     = array_unique($cats_treatment_arr);
        $cats_treatment_string  = implode(',', $cats_treatment_arr);
        $total_categories       = count($cats_treatment_arr);
        $select = [ 
            'uid'        
        ];

        // Aplicar la condición HAVING si $treatment_ids no está vacío
        $having = "";
        $i_treats = 0;

        $str_query_1 = "SELECT SysUsers.uid
                FROM sys_users SysUsers
                WHERE SysUsers.uid NOT IN ('".implode(",", $exclude)."')
                    AND SysUsers.steps = 'HOME'
                    AND SysUsers.active = 1           
                    AND SysUsers.deleted = 0            
                HAVING (SELECT COUNT(DISTINCT Category.type)
                            FROM data_treatments_prices Prices 
                                    INNER JOIN cat_treatments_ci Treatments ON Prices.treatment_id = Treatments.id
                                    INNER JOIN cat_treatments_category Category ON Treatments.category_treatment_id = Category.id                                
                            WHERE Prices.deleted = 0 AND Treatments.deleted = 0 AND Category.type IN (".$cats_treatment_string.") AND Prices.user_id = SysUsers.id
                            GROUP BY Prices.user_id
                        ) >= ".$total_categories. " ";

        $str_query = $str_query_1 . " LIMIT ".$limit." OFFSET ".(($page - 1) * $limit);

        $providers_query       = $this->SysUsers->getConnection()->execute($str_query)->fetchAll('assoc');
        $providers_query_count = $this->SysUsers->getConnection()->execute($str_query_1)->fetchAll('assoc');  

        // return array(
        //     'providers_query'  => $providers_query,
        //     'total_categories' => $total_categories,
        //     'categories'       => $cats_treatment_string
        // );        
        
        $provider_profiles = array();                 
        foreach ($providers_query as $key => $provider) {  
            $provider_profile     = ProviderProfile::get_profile_data($provider['uid']);
            $provider_profiles[]  = $provider_profile;    
        } 

        $total = count($providers_query_count);        

        return array(
            'total'        => $total,
            'data'         => $provider_profiles,
            'has_more'     => $total > ($page * $limit) ? true : false,
            'has_previous' => $page > 1 ? true : false,
        );
    }

    #endregion
}


// 🍑🍑🍑
// 🍑🍑🍑 Easter egg, reclama tu coca cola gratis
// 🍑🍑🍑