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

class TreatmentsHelper {
    #region PROPERTIES
    public $treatment_id;
    public $treatment;
    public $treatment_categories;
    public $treatment_catalog;

    public function __construct($treatment_id)
    {
        $THD = new TreatmentHelperData();        
        $this->treatment_id = $treatment_id;        
        $this->treatment            = $THD->get_treatment($treatment_id);
        $this->treatment_categories = $THD->get_treatment_categories($treatment_id);
        $this->treatment_catalog    = $THD->get_treatment_catalog($treatment_id);
    }   
}

class TreatmentHelperData extends AppPluginController
{
    public function __construct()
    {
        $this->loadModel('MySpaLiveV1.CatTreatmentsCi');
        $this->loadModel('MySpaLiveV1.DataTreatment');
        $this->loadModel('MySpaLiveV1.DataTreatmentsPrices');
    }

    public function get_treatment($treatment_id)
    {
        $treatment = $this->DataTreatment->find()
            ->where(['DataTreatment.id' => $treatment_id])
            ->first();      

        return $treatment;
    }

    public function get_treatment_categories(
        $treatment_id, 
        $is_general = true ## if true then it show advanced and basic neurotoxins as neurotoxins
    ){
        $this->loadModel('SpaLiveV1.DataTreatmentsPrice');
        $treatment = $this->get_treatment($treatment_id);
        if($treatment == null){
            return [];
        }

        // parche para iv uber
        $arr_treat = explode(',', $treatment->treatments);
        $array_t = [];
        foreach($arr_treat as $key => $value){
            if($value == 0){
                $join = ['CTC' => ['table' => 'cat_treatments_ci', 'type' => 'INNER', 'conditions' => 'CTC.id = DataTreatmentsPrice.treatment_id']];
                $_where = ['DataTreatmentsPrice.user_id' => $treatment->assistance_id, 'DataTreatmentsPrice.deleted' => 0, 'CTC.category_treatment_id' => 1001, 'CTC.deleted' => 0];

                $treatment_prices = $this->DataTreatmentsPrice->find()->select(['CTC.id'])
                ->join($join)
                ->where($_where)->all();

                foreach($treatment_prices as $treatment_price){
                    $array_t[] = $treatment_price['CTC']['id'];
                }
            }else{
                $array_t[] = $value;
            }
        }

        $string_treatments = implode(',', $array_t);

        $cats_treatment = $this->CatTreatmentsCi->find()
            ->select(['name' => 'CTC.type'])
            ->join([
                'CTC' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'CTC.id = CatTreatmentsCi.category_treatment_id'],    
            ])
            ->where(['CatTreatmentsCi.id IN ('.$string_treatments.')'])
            ->group(['CTC.type'])
            ->toArray();      

        $cats_treatment_arr = [];
        foreach($cats_treatment as $cat){
            if(($cat['name'] == 'NEUROTOXINS BASIC' || $cat['name'] == 'NEUROTOXINS ADVANCED') && $is_general){
                if(!in_array('NEUROTOXINS', $cats_treatment_arr)){
                    $cats_treatment_arr[] = 'NEUROTOXINS';
                }
            }else{
                $cats_treatment_arr[] = $cat['name'];
            }
        }

        return array_unique($cats_treatment_arr);
    }    

    public function get_treatment_catalog($treatment_id)
    {
        $treatment = $this->get_treatment($treatment_id);
        if($treatment == null){
            return [];
        }

        $catalog = array();
        $is_uber_type = $treatment->type_uber == 1;
        $categories = $this->get_treatment_categories($treatment_id, false);
        
        $has_any_neurotoxin = in_array('NEUROTOXINS BASIC', $categories) || in_array('NEUROTOXINS ADVANCED', $categories);

        if($has_any_neurotoxin){            
            $categories[] = 'NEUROTOXINS BASIC';
            $categories[] = 'NEUROTOXINS ADVANCED';
        }            

        $_select = [
            'id'        => 'CTCI.id',
            'name'      => 'CTCI.name',
            'details'   => 'CTCI.details',           
            'category'  => 'CTC.type',
            'std_price' => 'CTCI.std_price',
            'injector_price' => 'DataTreatmentsPrices.price',
            'checkout' => 'ST.checkout',
        ];

        $ent_category_treatment = $this->DataTreatmentsPrices->find()
            ->select($_select)
            ->join([
                'CTCI' => ['table' => 'cat_treatments_ci', 'type' => 'INNER', 'conditions' => 'CTCI.id = DataTreatmentsPrices.treatment_id'],
                'CTC'  => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'CTC.id = CTCI.category_treatment_id'],
                'CT'  => ['table' => 'cat_treatments', 'type' => 'INNER', 'conditions' => 'CT.id = CTCI.treatment_id'],
                'ST'  => ['table' => 'sys_treatments_ot', 'type' => 'LEFT', 'conditions' => 'ST.id = CT.other_treatment_id'],
            ])
            ->where([
                'DataTreatmentsPrices.user_id' => $treatment->assistance_id, 
                'DataTreatmentsPrices.deleted' => 0,
                'CTCI.deleted' => 0,
                'CTC.type IN ' => $categories,
                'CTC.deleted'  => 0
            ])
            ->order(['CTC.order' => 'ASC'])
            ->toArray();
        
        foreach($ent_category_treatment as $cat_treatment){
            if ($is_uber_type){
                if ($cat_treatment['category'] == 'IV THERAPY' || $cat_treatment['category'] == 'IV' || $cat_treatment['category'] == 'FILLERS'){
                    $cat_treatment['price'] = $cat_treatment['injector_price'];
                }else{
                    $cat_treatment['price'] = $cat_treatment['std_price'];
                }
            }else{
                $cat_treatment['price'] = $cat_treatment['injector_price'];
            }

            $cat_treatment['price'] = intval($cat_treatment['price']);

            if(empty($cat_treatment['checkout'])){
                $cat_treatment['payment_options'] = [];
            }else{
                $cat_treatment['payment_options'] = $cat_treatment['checkout'] == 'CC only' ? ["CARD"] : ["CARD", "CASH"];
            }
        }
        
        $categorized_catalog = array();
        foreach($ent_category_treatment as $cat_treatment){
            $categorized_catalog[$cat_treatment['category']][] = $cat_treatment;    
        }

        foreach($categorized_catalog as $key => $cat){
            $catalog[] = $this->get_category_component($key, $cat, $treatment->assistance_id);
        }

        return $catalog;
    }

    public $category_names =[
        "NEUROTOXINS BASIC"     => 'Basic Neurotoxins',
        "NEUROTOXINS ADVANCED"  => 'Advanced Neurotoxins',
        "FILLERS"               => 'Fillers',
        "IV THERAPY"            => 'IV Therapy',
        "OTHER TREATMENTS"      => 'Other treatments',
    ];

    public function get_category_component($type_category, $treatments, $assistance_id){
        $one_child          = count($treatments) == 1;
        $multiple_selection = true;
        $editable_units     = false;
        $type               = '';
        $payment_type_level = 'CATEGORY';
        $payment_options = ["CARD"];
        switch($type_category){
            case 'FILLERS':
                $editable_units = true;
                $type = 'BY_MILLILITER';
                break;
            case 'NEUROTOXINS BASIC':
                $editable_units = true;
                $type = 'BY_UNIT';
                break;
            case 'IV THERAPY':
                $type = 'BY_IV_THERAPY';
                $editable_units = false;
                break;
            case 'NEUROTOXINS ADVANCED':
                $type = 'BY_UNIT';     
                $editable_units = true;           
                break;
            case 'OTHER TREATMENTS':
                $type = 'BY_UNIT';     
                $editable_units = true;
                $payment_type_level = 'INDIVIDUAL';
                $payment_options = [];
                break;
            default :
                $editable_units     = false;
                $type = 'BY_UNIT';
        }

        $name = $this->category_names[$type_category];
        return array(
            'name'               => $name == 'Basic Neurotoxins' ? ($this->check_training_medical($assistance_id) ? 'Neurotoxins' : $name) : $name,
            'treatments'         => $treatments,
            'component'          => $type,
            'multiple_selection' => $multiple_selection,
            'editable_units'     => $editable_units,
            'self_named'         => $one_child && $treatments[0]['name'] == $name,
            'payment_type_level' => $payment_type_level,
            'payment_options'    => $payment_options
        ); 
    }

    public function check_training_medical($user_id){
        return NeuroLevel3AccessHelper::userHasNeuroLevel3Access((int) $user_id);
    }
}
