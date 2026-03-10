<?php
declare(strict_types=1);

namespace SpaLiveV1\Controller;

use App\Controller\AppPluginController;
use Cake\Utility\Security;
use Cake\Utility\Text;

use Spipu\Html2Pdf\Html2Pdf;
use Spipu\Html2Pdf\Exception\Html2PdfException;
use Spipu\Html2Pdf\Exception\ExceptionFormatter;
use Stripe\Util\Set;
use Cake\Core\Configure;

class PatientModelController extends AppPluginController {
    
    #region INITIALIZE
    private $total = 3900;
    private $paymente_gfe = 1800;
    private $register_total = 79500;
    private $register_refund = 3500;
    private $training_advanced = 79500;

    public function initialize() : void {
        parent::initialize();
        date_default_timezone_set("America/Chicago");
        $this->URL_API = env('URL_API', 'https://api.myspalive.com/');
        $this->URL_PANEL = env('URL_PANEL', 'https://panel.myspalive.com/');
    }

    public function get_labels_register(){
        $this->loadModel('SpaLiveV1.CatLabels');

        $model = get('model', '');
        

        $userType = get('usertype', '');
        $where = ['CatLabels.deleted' => 0, 'CatLabels.tipo' => $model];

        if(!empty($userType)){
            $where['CatLabels.key_field'] = $userType == 'patient' ? 'register_patient' : ( $userType == 'examiner' ? 'register_gfe' : ( $userType == 'clinic' ? 'register_clinic' : ($userType == 'injector' ? 'register_ci' : '') ) );
        }
        
        $findLabels = $this->CatLabels->find()->select(['CatLabels.key_field', 'CatLabels.value'])->where($where)->toArray();

        if(!empty($findLabels)){
            $labels = [];
            foreach($findLabels as $item){
                $any = ($this->register_total/100) - ($this->register_refund/100);
                $labels[$item->key_field] = str_replace('GFE_COST', $this->total/100 , $item->value);
                $labels[$item->key_field] = str_replace('GFE_DOUBLE', ($this->total/100)*2, $labels[$item->key_field]);
                $labels[$item->key_field] = str_replace('GFE_PAYMENT', $this->paymente_gfe/100 , $labels[$item->key_field]);
                $labels[$item->key_field] = str_replace('CI_REGISTRATION', $this->register_total/100 , $labels[$item->key_field]);
                $labels[$item->key_field] = str_replace('CI_ADVANCED', $this->training_advanced/100 , $labels[$item->key_field]);
                $labels[$item->key_field] = str_replace('CI_REFUND', $this->register_refund/100 , $labels[$item->key_field]);
                $labels[$item->key_field] = str_replace('CI_REST ', $any , $labels[$item->key_field]);
                $this->set($item->key_field, $labels[$item->key_field]);
            }

            $this->success();
            $this->set('video_url', Configure::read('App.wordpress_domain') .'myspa.mp4');
            $this->set('video_url_patient', Configure::read('App.wordpress_domain') .'patient.mp4');  
        }
    }

    public function get_user_agreements(){               
        
        $result = array();        

        $protocols = array();

        $protocols[] = array('title' => 'Name of Delegating Physician','url' => 'https://blog.myspalive.com/wp-content/uploads/protocols/Name-of-Delegating-Physician.pdf');
        $protocols[] = array('title' => 'MySpaLive Office Protocol','url' => 'https://blog.myspalive.com/wp-content/uploads/protocols/Office-Protocol-2.pdf');
        $user_type = $userType = get('usertype', 'patient');

        if ($user_type == 'injector') {
            $protocols[] = array('title' => 'Protocol for MySpaLive Certified Injectors','url' => 'https://blog.myspalive.com/wp-content/uploads/protocols/Protocol-for-Injectors-1-1.pdf');
            $protocols[] = array('title' => 'Pre-Course Study Materials','url' => 'https://blog.myspalive.com/wp-content/uploads/protocols/FacialAnatomy.pdf');
            $protocols[] = array('title' => 'MySpaLive video','url' => 'https://blog.myspalive.com/myspa.mp4');
            /* $protocols[] = array('title' => 'Ideals of Beauty and Methods of Body Modification','url' => 'https://blog.myspalive.com/wp-content/uploads/2022/08/Ideals-of-Beauty-and-Methods-of-Body-Modification.ppt');
            $protocols[] = array('title' => 'Ordering and Storage Instructions','url' => 'https://blog.myspalive.com/wp-content/uploads/2022/08/ordering_and_storage_guide_with_pi.pdf'); */
            $protocols[] = array('title' => 'Face sheet','url' => 'https://blog.myspalive.com/wp-content/uploads/protocols/face_sheet.pdf');
        } else if ($user_type == 'examiner') {
            $protocols[] = array('title' => 'Standing Orders for Examiners','url' => 'https://blog.myspalive.com/wp-content/uploads/protocols/Standing-Orders-for-Examiners-1.pdf');
            $protocols[] = array('title' => 'Protocol for MySpalive Midlevel Providers for the good faith exam','url' => 'https://blog.myspalive.com/wp-content/uploads/protocols/Protocol-for-Examiners-1.pdf');
        } else if ($user_type == 'patient') {
            $protocols[] = array('title' => 'Notice Concerning Complaints English','url' => 'https://blog.myspalive.com/wp-content/uploads/protocols/NOTICE-CONCERNING-COMPLAINTS-English.pdf');
            $protocols[] = array('title' => 'Aviso Sobre Las Quejas Spanish','url' => 'https://blog.myspalive.com/wp-content/uploads/protocols/AVISO-SOBRE-LAS-QUEJAS-Spanish-1.pdf');
            $protocols[] = array('title' => 'Notice of Privacy Practices Including Photography','url' => 'https://blog.myspalive.com/wp-content/uploads/protocols/Notice-of-Privacy-Practices-Including-Photography-2.pdf');
            $protocols[] = array('title' => 'Patient consent to treatment Informed Consent','url' => 'https://blog.myspalive.com/wp-content/uploads/protocols/Patient-Consent-to-Treatment_Informed-consent-1.pdf');
            $protocols[] = array('title' => 'Treatment post booking','url' => 'https://blog.myspalive.com/wp-content/uploads/protocols/treatment_post_booking.pdf');
            $this->loadModel('SpaLiveV1.DataModelPatientDocs');            
            $docs = $this->DataModelPatientDocs->find()->where(['DataModelPatientDocs.user_id' => USER_ID, 'DataModelPatientDocs.deleted' => 0])->toArray();
            foreach ($docs as $doc) {
                if($doc['type'] == 'INFO'){
                    $protocols[] = array('title' => 'What is a patient model?', 'url' => $this->URL_API . '?action=PatientModel____generate_pdf_what_is_pm&key=225sadfgasd123fgkhijjdsadfg16578g12gg3gh&id=' . USER_ID);
                }
                else if($doc['type'] == 'GFE'){
                    $protocols[] = array('title' => 'GFE payment confirmation', 'url' => $this->URL_API . '?action=PatientModel____generate_pdf_gfe_payment&key=225sadfgasd123fgkhijjdsadfg16578g12gg3gh&id=' . USER_ID);
                }
            }
        } else if ($user_type == 'gfe+ci') {
            $protocols[] = array('title' => 'Protocol for MySpaLive Certified Injectors','url' => 'https://blog.myspalive.com/wp-content/uploads/protocols/Protocol-for-Injectors-1-1.pdf');
            $protocols[] = array('title' => 'Standing Orders for Examiners','url' => 'https://blog.myspalive.com/wp-content/uploads/protocols/Standing-Orders-for-Examiners-1.pdf');
            $protocols[] = array('title' => 'Protocol for MySpalive Midlevel Providers for the good faith exam','url' => 'https://blog.myspalive.com/wp-content/uploads/protocols/Protocol-for-Examiners-1.pdf');
            $protocols[] = array('title' => 'Pre-Course Study Materials','url' => 'https://blog.myspalive.com/wp-content/uploads/protocols/FacialAnatomy.pdf');
            $protocols[] = array('title' => 'MySpaLive video','url' => 'https://blog.myspalive.com/myspa.mp4');
            /* $protocols[] = array('title' => 'Ideals of Beauty and Methods of Body Modification','url' => 'https://blog.myspalive.com/wp-content/uploads/2022/08/Ideals-of-Beauty-and-Methods-of-Body-Modification.ppt');
            $protocols[] = array('title' => 'Ordering and Storage Instructions','url' => 'https://blog.myspalive.com/wp-content/uploads/2022/08/ordering_and_storage_guide_with_pi.pdf'); */
            $protocols[] = array('title' => 'Face sheet','url' => 'https://blog.myspalive.com/wp-content/uploads/protocols/face_sheet.pdf');
        }   
        
        $this->set('my_documents', array('protocols' => $protocols,'user_agreements' => $result));
        $this->success();
    }

    public function save_register_patient_model() {
        $this->loadModel('SpaLiveV1.DataPreRegister');
        $this->loadModel('SpaLiveV1.SysUsers');              

        $phone = get('phone', '');
        if (empty($phone)) {
            $this->message('Phone number empty.');
            return;
        }

        $email = get('email', '');
        if (empty($email)) {
            $this->message('Email address empty.');
            return;
        }

        $userType = get('type', 'patient');            

        $passwd = get('password', '');

        $arr_dob = explode("-", get('dob','2002-01-01'));
        $str_dob = "";
        
        if (count($arr_dob) == 3) {
            $year = intval($arr_dob[0]);            
            $str_dob = $arr_dob[0] . '-' . $arr_dob[1] . '-' . $arr_dob[2];
        }

        if(empty($str_dob)){
            $this->message('Invalid DOB.');
            return;
        }
                
        $created = date('m-d-Y');
        $treatment_type ="";                
        $Main = new MainController();
        $shd = false;
        $short_uid = '';    
        do {

            $num = substr(str_shuffle("0123456789"), 0, 4);
            $short_uid = $num . "" . strtoupper($Main->generateRandomString(4));

            $existUser = $this->SysUsers->find()->where(['SysUsers.short_uid LIKE' => $short_uid])->first();
        if(empty($existUser))
            $shd = true;

        } while (!$shd);
        
        $array_save = array(
            'uid'           => $this->SysUsers->new_uid(),
            'short_uid'     => $short_uid,
            'email'         => get('email', ''), //
            'name'          => get('name', ''), //            
            'lname'         => get('lname', ''), //
            'type'          => get('type', 'patient'),             
            'password'      => hash_hmac('sha256', $passwd, Security::getSalt()),            
            'phone'         => get('phone', ''), //      
            'active'        => 1,      
            'steps'         => 'CODEVERIFICATION',            
            'dob'           => $str_dob,
            'zip'           => get('zip',''),                                    
            'state'         => get('state',0),            
            'street'        => get('street',''),
            'city'          => get('city',''),            
            'active'        => get('active',1),
            'gender'        => get('gender', 'Other'),
            'suite'         => get('suite',''),
        );
        

        $existUser = $this->SysUsers->find()->where(['SysUsers.email LIKE' => strtolower($email)])->first();

        if(empty($existUser)){
            $c_entity = $this->SysUsers->newEntity($array_save);
            if(!$c_entity->hasErrors()) {
                
                $this->save_model_patient();

                $id = $this->SysUsers->save($c_entity);

                #region SAVE USER IN sys_users_register TABLE

                $this->loadModel('SpaLiveV1.SysUsersRegister');

                $array_save_recommendation = array(
                    'user_id'       => $id->id,
                    'source'      => get('recommendation',''),
                );

                $c_entity_about = $this->SysUsersRegister->newEntity($array_save_recommendation);

                if(!$c_entity_about->hasErrors()) {
                    $this->SysUsersRegister->save($c_entity_about);
                }

                #endregion

                $this->message(json_encode($id));

                $this->set('user_id', $id->id);

                $this->success();


                $user = $this->SysUsers->find()->where(['SysUsers.short_uid ' => $short_uid])->first();
                if(!empty($user)){                 
                    $created =  $user->created->i18nFormat('MM-dd-yyyy');
                }                        
            }
            
        } else {
            if($existUser->deleted == 1){
                $this->message('The email address you are using belongs to an account that has been deleted.');
                return;
            } else {
                $this->message('The email address you are using already belongs to an account active.');
                return;
            }
            
        }
        return;
    }

    private function save_model_patient()
    {
        $this->loadModel('SpaLiveV1.DataModelPatient');
        $this->loadModel('SpaLiveV1.SysUsers');                
        $email = get('email',' ');
        $_where = ['DataModelPatient.deleted' => 0, 'DataModelPatient.email LIKE' => $email];
        
        $existUser = $this->DataModelPatient->find()->where($_where)->all();
        if(count($existUser) > 0){            
            foreach ($existUser as $row) {                
                $this->DataModelPatient->updateAll(
                    ['email' => "_".$row->email], 
                    ['id' => $row->id]
                );
            }
            $this->success(); 
        }else{
            $array_save = array(
                'uid' => Text::uuid(),
                'name' => get('name',''),
                'mname' => get('mname',''),
                'lname' => get('lname',''),
                'email' => get('email',''),
                'phone' => get('phone',''),
                'requested_training_id' => intval(get('training','0')) ,
                'gfe' => get('gfe',''),
                'understand' => get('free',''),
                'status' => 'not assigned',
            );

            $c_entity = $this->DataModelPatient->newEntity($array_save);
            if(!$c_entity->hasErrors()) {
                if ($this->DataModelPatient->save($c_entity)) {
                    $this->success(); 
                }
            }
        }
        
    }

    public function generate_pdf_what_is_pm()
    {   
        $id_user = get('id', '');
        
        $this->loadModel('SpaLiveV1.CatLabels');
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataModelPatientDocs');
        
        $labels = $this->CatLabels->find()->select(['CatLabels.uid', 'CatLabels.name', 'CatLabels.value', 'CatLabels.key_field'])->where(['CatLabels.deleted' => 0, 'CatLabels.tipo' => 'REGISTER_PATIENT_MODEL', 'CatLabels.key_field LIKE' => 'info_patient_model%'])->toArray();
        $user = $this->SysUsers->find()->where(['SysUsers.id' => $id_user])->toArray();
        $doc = $this->DataModelPatientDocs->find()->where(['DataModelPatientDocs.user_id' => $id_user, 'DataModelPatientDocs.deleted' => 0, 'DataModelPatientDocs.type' => 'INFO'])->toArray();
        
        $html_bulk = "";
        if($doc) {
            $html_bulk .= $doc[0]->html_content;

            $filename = 'WhatIsAPatientModel' . $id_user . '.pdf';
            $html2pdf = new HTML2PDF('F','A4','en', true, 'UTF-8', array(0,0,0,0));
            $html2pdf->writeHTML($html_bulk);
            $html2pdf->Output($filename, 'I'); 
        } else {
            $html_bulk .= "
                <page>
                    <div style='width: 210mm; height: 97mm; position:relative;'>
                        <div style='width:210mm; height: 97mm; position:absolute; z-index: 2;'>
                            <div style='top:30mm;'>
                                <img src='" . $this->URL_API . "img/logo.png' style='width=50mm;'/>
                            </div>
                            <div align='center'><h1><b>What is a patient model?</b></h1></div>";
            $top = 70;
            foreach($labels as $label) {
                $html_bulk .= "                                
                                    <div style='position: absolute;left: 12mm;top: ".$top."mm; width: 190mm; background-color: white'><p>".$label->value."</p></div>
                            ";
                $top = $top + 20;
            }
            $html_bulk .= "
                            <div style='position: absolute;left: 12mm;top: ".$top."mm; width: 190mm;'>
                                <span><b>" . $user[0]->name . " " . $user[0]->lname . "</b></span>
                                <br>
                                <span>" . date('Y-m-d H:i:s') . "</span>
                            </div>
                        </div>
                    </div>
                </page>";

            $array_save = array(
                "user_id" => $id_user,
                "html_content" => $html_bulk,
                "type" => 'INFO',
                "created" => date('Y-m-d H:i:s'),
            );

            $c_entity = $this->DataModelPatientDocs->newEntity($array_save);
            if(!$c_entity->hasErrors()) {
                if ($this->DataModelPatientDocs->save($c_entity)) {
                    $this->success(); 
                }
            }
        }

        
    }

    public function generate_pdf_gfe_payment()
    {   
        $this->loadModel('SpaLiveV1.CatLabels');
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataModelPatientDocs');

        $uid = get('uid', '');
        if($uid == ''){
            $id = get('id', '');
            $user = $this->SysUsers->find()->where(['SysUsers.id' => $id])->toArray();    
            $uid = $user[0]->uid;
        }
        
        $labels = $this->CatLabels->find()->select(['CatLabels.uid', 'CatLabels.name', 'CatLabels.value', 'CatLabels.key_field'])->where(['CatLabels.deleted' => 0, 'CatLabels.tipo' => 'REGISTER_PATIENT_MODEL', 'CatLabels.key_field LIKE' => 'gfe_patient_model%'])->toArray();
        $user = $this->SysUsers->find()->where(['SysUsers.uid' => $uid])->toArray();    
        $id_user = $user[0]->id;
        $doc = $this->DataModelPatientDocs->find()->where(['DataModelPatientDocs.user_id' => $id_user, 'DataModelPatientDocs.deleted' => 0, 'DataModelPatientDocs.type' => 'GFE'])->toArray();
        
        $html_bulk = "";
        if($doc) {  
            // $html_bulk .= $doc[0]->html_content;

            $ddate = $doc[0]->created->i18nFormat('MM/dd/yyyy');
            // $img_html = $doc[0]->file_id > 0 ? "<img style='heignt:50mm; width:50mm;' src='" . $this->URL_API . "?key=2fe548d5ae881ccfbe2be3f6237d7951&l3n4p=6092482f7ce858.91169218&action=get-file&id=" . $doc[0]->file_id . "'>" : '';
            $img_html = $doc[0]->file_id > 0 ? "<img src='" . $this->URL_API . "api/?key=225sadfgasd123fgkhijjdsadfg16578g12gg3gh&l3n4p=6092482f7ce858.91169218&action=get-file&id={$doc[0]->file_id}' />" : '';
            $html_bulk = $doc[0]->html_content . "<br><br><div style='position: absolute;left: 12mm;top: 165mm; width: 190mm;'><b>Agreed/Signed by:</b> " . $user[0]->name . " " . $user[0]->lname . "<br>Date: " . $ddate . "<br>" . $img_html . "</div>";
            
            $filename = 'GFEPaymentPatientModel' . $id_user . '.pdf';
            $html2pdf = new HTML2PDF('F','A4','en', true, 'UTF-8', array(0,0,0,0));
            $html2pdf->writeHTML($html_bulk);
            $html2pdf->Output($filename, 'I'); 
        } 
        else    
        {
            $_file_id = 0;
            if (!empty($_FILES)) {
                $_file_id = $this->Files->upload([
                    'name' => $_FILES['file']['name'],
                    'type' => $_FILES['file']['type'],
                    'path' => $_FILES['file']['tmp_name'],
                    'size' => $_FILES['file']['size'],
                ]);
            }

            // if($_file_id <= 0){
            //     $this->message('Error in save content file.');
            //     return;
            // }

            $html_bulk .= "
                    <div style='width: 210mm; height: 97mm; position:relative;'>
                        <div style='width:210mm; height: 97mm; position:absolute; z-index: 2;'>
                            <div style='top:30mm;'>
                                <img src='" . $this->URL_API . "img/logo.png' style='width=50mm;'/>
                            </div>
                            <div align='center'><h1><b>GFE payment confirmation</b></h1></div>";
                $html_bulk .= "                                
                            <div style='position: absolute;left: 12mm;top: 70mm; width: 190mm; background-color: white'><p>".$labels[0]->value."</p></div>
                        
                            <div style='position: absolute;left: 12mm;top: 85mm; width: 190mm; background-color: white'><div align='center'><p><h4>Total: $39</h4></p></div></div>

                            <div style='position: absolute;left: 12mm;top: 110mm; width: 190mm; background-color: white'><p>".$labels[1]->value."</p></div>

                            <div style='position: absolute;left: 12mm;top: 125mm; width: 190mm; background-color: white'><p>".$labels[2]->value."</p></div>

                            <div style='position: absolute;left: 12mm;top: 140mm; width: 190mm; background-color: white'><p>".$labels[3]->value."</p></div>
                            ";
                $html_bulk .="
                        </div>
                    </div>";
            
            $array_save = array(
                "user_id" => $id_user,
                "html_content" => $html_bulk,
                "type" => 'GFE',
                "file_id" => $_file_id,
                "created" => date('Y-m-d H:i:s'),
            );

            $c_entity = $this->DataModelPatientDocs->newEntity($array_save);
            if(!$c_entity->hasErrors()) {
                if ($this->DataModelPatientDocs->save($c_entity)) {
                    $this->success(); 
                }
            }

            // $filename = 'GFEPaymentPatientModel' . $id_user . '.pdf';
            // $html2pdf = new HTML2PDF('F','A4','en', true, 'UTF-8', array(0,0,0,0));
            // $html2pdf->writeHTML($html_bulk);
            // $html2pdf->Output($filename, 'I'); 
        }
    }  
}

// "<img src='" . $this->URL_API . "?key=2fe548d5ae881ccfbe2be3f6237d7951&l3n4p=6092482f7ce858.91169218&action=get-file&token=6092482f7ce858.91169218&id={$_file_id} style='height:100; width:100;'>"